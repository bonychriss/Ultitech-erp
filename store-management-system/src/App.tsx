import React, { useCallback, useEffect, useMemo, useState } from 'react';
import {
  AlertCircle,
  ArrowDownCircle,
  ArrowLeft,
  ArrowUpCircle,
  Boxes,
  Inbox,
  Loader2,
  Search,
  Warehouse as WarehouseIcon,
  X,
} from 'lucide-react';
import MovementsList from './components/MovementsList';
import MovementDetail from './components/MovementDetail';
import StoreOutgoingForm from './components/StoreOutgoingForm';
import StoreReceiveForm from './components/StoreReceiveForm';
import ExportMenu from './components/ExportMenu';
import ExportPdfModal, { type ExportPdfRange } from './components/ExportPdfModal';
import WarningConfirmPopup from './components/WarningConfirmPopup';
import { deleteWarehouseMovement, fetchInit, fetchMovements, fetchProducts } from './api';
import { exportMovementsPdf } from './utils/exportMovementsPdf';
import type { Product, StockMovement, StoreConfig, Warehouse } from './types';
import type { OutgoingKind } from './components/StoreOutgoingForm';

type MovementFilter = 'all' | 'in' | 'out';
type DeskView = 'list' | 'receive' | 'outgoing' | 'detail';

function readDeskViewFromUrl(): DeskView {
  const params = new URLSearchParams(window.location.search);
  const raw = (params.get('view') || '').toLowerCase();
  if (raw === 'receive' || raw === 'outgoing') return raw;
  return 'list';
}

/** Update query string without dropping module=warehouses (or other existing params). */
function syncDeskUrl(patch: { view?: DeskView; warehouseId?: number | null }) {
  const params = new URLSearchParams(window.location.search);
  if (patch.view !== undefined) {
    if (patch.view === 'receive' || patch.view === 'outgoing') {
      params.set('view', patch.view);
    } else {
      params.delete('view');
    }
  }
  if (patch.warehouseId !== undefined) {
    if (patch.warehouseId && patch.warehouseId > 0) {
      params.set('warehouse_id', String(patch.warehouseId));
    } else {
      params.delete('warehouse_id');
    }
  }
  const qs = params.toString();
  const next = `${window.location.pathname}${qs ? `?${qs}` : ''}${window.location.hash}`;
  const current = `${window.location.pathname}${window.location.search}${window.location.hash}`;
  if (next !== current) {
    window.history.replaceState({}, '', next);
  }
}

export default function App() {
  const [warehouses, setWarehouses] = useState<Warehouse[]>([]);
  const [products, setProducts] = useState<Product[]>([]);
  const [movements, setMovements] = useState<StockMovement[]>([]);
  const [config, setConfig] = useState<StoreConfig | null>(null);
  const [warehouseId, setWarehouseId] = useState<number | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadingMovements, setLoadingMovements] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [view, setView] = useState<DeskView>(() => readDeskViewFromUrl());
  const [searchTerm, setSearchTerm] = useState('');
  const [movementFilter, setMovementFilter] = useState<MovementFilter>('all');
  const [outgoingProductId, setOutgoingProductId] = useState<string | null>(null);
  const [outgoingKind, setOutgoingKind] = useState<OutgoingKind>('sold');
  const [selectedMovement, setSelectedMovement] = useState<StockMovement | null>(null);
  const [exportingPdf, setExportingPdf] = useState(false);
  const [exportPdfOpen, setExportPdfOpen] = useState(false);
  const [exportPdfError, setExportPdfError] = useState<string | null>(null);
  const [deletingId, setDeletingId] = useState<string | null>(null);
  const [pendingDelete, setPendingDelete] = useState<StockMovement | null>(null);

  const canDeleteRecords = Boolean(config?.isSystemAdmin);

  const loadMovements = useCallback(async (whId: number, search = searchTerm) => {
    setLoadingMovements(true);
    setError(null);
    try {
      const data = await fetchMovements(whId, {
        search: search.trim() || undefined,
      });
      setMovements(data.movements.filter((m) => m.movementType === 'in' || m.movementType === 'out'));
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load movements');
    } finally {
      setLoadingMovements(false);
    }
  }, [searchTerm]);

  const loadProducts = useCallback(async (whId: number) => {
    try {
      const list = await fetchProducts(whId);
      setProducts(list);
    } catch {
      setProducts([]);
    }
  }, []);

  useEffect(() => {
    (async () => {
      setLoading(true);
      setError(null);
      try {
        const data = await fetchInit();
        setWarehouses(data.warehouses.filter((w) => w.isActive));
        setConfig(data.config);

        const defaultWarehouse = (() => {
          const params = new URLSearchParams(window.location.search);
          const fromUrl = Number(params.get('warehouse_id'));
          if (fromUrl > 0 && data.warehouses.some((w) => w.id === fromUrl && w.isActive)) {
            return data.warehouses.find((w) => w.id === fromUrl)!;
          }
          return data.warehouses.find((w) => w.isActive);
        })();

        if (defaultWarehouse) {
          setWarehouseId(defaultWarehouse.id);
          await Promise.all([
            loadMovements(defaultWarehouse.id, ''),
            loadProducts(defaultWarehouse.id),
          ]);
        }
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to initialize store management');
      } finally {
        setLoading(false);
      }
    })();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [loadProducts]);

  useEffect(() => {
    if (!warehouseId || loading) return;
    const timer = window.setTimeout(() => {
      loadMovements(warehouseId, searchTerm);
    }, 250);
    return () => window.clearTimeout(timer);
  }, [warehouseId, searchTerm, loadMovements, loading]);

  useEffect(() => {
    syncDeskUrl({ view, warehouseId });
  }, [view, warehouseId]);

  const selectedWarehouse = warehouses.find((w) => w.id === warehouseId) ?? null;

  const listedMovements = useMemo(() => {
    const withImages = movements.map((m) => ({
      ...m,
      imageUrl: m.imageUrl || products.find((p) => p.id === m.productId)?.imageUrl || '',
    }));
    if (movementFilter === 'all') return withImages;
    return withImages.filter((m) => m.movementType === movementFilter);
  }, [movements, movementFilter, products]);

  const stats = useMemo(() => {
    const incoming = movements.filter((m) => m.movementType === 'in');
    const outgoing = movements.filter((m) => m.movementType === 'out');
    const totalIn = incoming.reduce((sum, m) => sum + m.quantity, 0);
    const totalOut = outgoing.reduce((sum, m) => sum + m.quantity, 0);
    return {
      records: movements.length,
      incomingCount: incoming.length,
      outgoingCount: outgoing.length,
      totalIn,
      totalOut,
    };
  }, [movements]);

  const refreshData = useCallback(async () => {
    if (!warehouseId) return;
    await Promise.all([
      loadMovements(warehouseId, searchTerm),
      loadProducts(warehouseId),
    ]);
  }, [warehouseId, loadMovements, loadProducts, searchTerm]);

  const openOutgoing = (kind: OutgoingKind = 'sold', product: Product | null = null) => {
    setOutgoingKind(kind);
    setOutgoingProductId(product?.id ?? null);
    setSelectedMovement(null);
    setView('outgoing');
  };

  const openMovementDetail = (movement: StockMovement) => {
    setSelectedMovement(movement);
    setView('detail');
  };

  const handleDeleteMovement = async (movement: StockMovement) => {
    if (!warehouseId || !canDeleteRecords) return;
    setPendingDelete(movement);
  };

  const confirmPendingDelete = async () => {
    if (!warehouseId || !pendingDelete || !canDeleteRecords) return;
    const movement = pendingDelete;
    setDeletingId(movement.id);
    setError(null);
    try {
      await deleteWarehouseMovement(warehouseId, movement.id);
      setPendingDelete(null);
      if (selectedMovement?.id === movement.id) {
        setSelectedMovement(null);
        setView('list');
      }
      await refreshData();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to delete product record');
    } finally {
      setDeletingId(null);
    }
  };

  const openExportPdf = () => {
    setExportPdfError(null);
    setExportPdfOpen(true);
  };

  const handleExportPdf = async (range: ExportPdfRange) => {
    if (!warehouseId) return;

    setExportingPdf(true);
    setExportPdfError(null);
    setError(null);

    try {
      const filterLabel =
        movementFilter === 'in' ? 'Incoming' : movementFilter === 'out' ? 'Outgoing' : 'All movements';

      const { movements: exportRows } = await fetchMovements(warehouseId, {
        search: searchTerm.trim() || undefined,
        type: movementFilter === 'all' ? undefined : movementFilter,
        startDate: range.allTime ? undefined : range.startDate,
        endDate: range.allTime ? undefined : range.endDate,
      });

      const rows = exportRows
        .filter((m) => m.movementType === 'in' || m.movementType === 'out')
        .map((m) => ({
          ...m,
          imageUrl: m.imageUrl || products.find((p) => p.id === m.productId)?.imageUrl || '',
        }));
      if (rows.length === 0) {
        setExportPdfError('No movements found for the selected date range.');
        return;
      }

      await exportMovementsPdf({
        movements: rows,
        warehouseName: selectedWarehouse?.name || selectedWarehouse?.code,
        companyName: config?.companyName,
        companyLogoUrl: config?.companyLogoUrl,
        filterLabel,
        searchTerm,
      });

      setExportPdfOpen(false);
    } catch (err) {
      setExportPdfError(err instanceof Error ? err.message : 'Failed to export PDF report');
    } finally {
      setExportingPdf(false);
    }
  };

  if (loading) {
    return (
      <div className="sms-desk-page sms-desk-boot-loading" role="status">
        <Loader2 className="sms-desk-boot-spinner" aria-hidden="true" />
        <span>Loading store...</span>
      </div>
    );
  }

  if (!warehouseId || !selectedWarehouse) {
    return (
      <div className="sms-desk-page sms-desk-empty">
        <WarehouseIcon className="sms-desk-empty-icon" />
        <p className="sms-desk-empty-title">
          {error ? 'Could not load warehouse' : 'No active warehouse found'}
        </p>
        <p className="sms-desk-empty-sub">
          {error || 'Create a warehouse from the Warehouses menu first.'}
        </p>
        {!error && (
          <a href={config?.manageWarehousesUrl || '#'} className="sms-desk-btn sms-desk-btn-secondary">
            Go to Warehouses
          </a>
        )}
        {error && (
          <button
            type="button"
            className="sms-desk-btn sms-desk-btn-secondary"
            onClick={() => window.location.reload()}
          >
            Retry
          </button>
        )}
      </div>
    );
  }

  if (view === 'receive' || view === 'outgoing' || view === 'detail') {
    const isExcelReceive = view === 'receive';
    return (
      <div className={`sms-desk-page${isExcelReceive ? ' sms-desk-page--excel-receive' : ''}`}>
        {isExcelReceive ? (
          <div className="sms-excel-page-top">
            <button
              type="button"
              className="sms-desk-back-link"
              onClick={() => {
                setSelectedMovement(null);
                setView('list');
              }}
            >
              <ArrowLeft className="w-3.5 h-3.5" />
              Back
            </button>
            <h3 className="sms-excel-page-title">Receive into stock</h3>
          </div>
        ) : (
          <div className="sms-desk-page-header sms-desk-page-header--simple">
            <button
              type="button"
              className="sms-desk-back-link"
              onClick={() => {
                setSelectedMovement(null);
                setView('list');
              }}
            >
              <ArrowLeft className="w-3.5 h-3.5" />
              Back
            </button>
          </div>
        )}
        {error && (
          <div className="sms-desk-flash sms-desk-flash-error" role="alert">
            <AlertCircle className="w-4 h-4 shrink-0" />
            <span>{error}</span>
          </div>
        )}
        {view === 'receive' ? (
          <StoreReceiveForm
            warehouseId={warehouseId}
            products={products}
            canReceivePurchaseOrders={config?.canReceivePurchaseOrders !== false}
            confirmPoToStock={config?.confirmPoToStock !== false && !config?.canManageProducts}
            onReceived={async () => {
              await refreshData();
            }}
          />
        ) : view === 'outgoing' ? (
          <StoreOutgoingForm
            warehouseId={warehouseId}
            products={products}
            preselectedProductId={outgoingProductId}
            initialKind={outgoingKind}
            onRecorded={async () => {
              await refreshData();
              setView('list');
            }}
          />
        ) : selectedMovement ? (
          <MovementDetail
            movement={selectedMovement}
            product={products.find((p) => p.id === selectedMovement.productId) ?? null}
          />
        ) : null}
      </div>
    );
  }

  return (
    <div className="sms-desk-page">
      <div className="sms-desk-page-header sms-desk-page-header--toolbar">
        <div className="sms-desk-page-header-search">
          <div className="sms-desk-search-field">
            <Search className="sms-desk-search-icon" aria-hidden="true" />
            <input
              type="search"
              className="sms-desk-search-input"
              placeholder="Search product, code, or notes..."
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
              aria-label="Search incoming and outgoing records"
            />
            {searchTerm.trim() !== '' && (
              <button
                type="button"
                className="sms-desk-search-clear"
                onClick={() => setSearchTerm('')}
                aria-label="Clear search"
              >
                <X className="w-4 h-4" />
              </button>
            )}
          </div>
        </div>

        <div className="sms-desk-page-header-actions" role="group" aria-label="Store actions">
          <button
            type="button"
            className="sms-desk-btn sms-desk-btn-secondary sms-btn-rounded"
            onClick={() => setView('receive')}
          >
            <ArrowDownCircle className="w-4 h-4" />
            <span>Receive</span>
          </button>
          <button
            type="button"
            className="sms-desk-btn sms-desk-btn-primary sms-btn-rounded"
            onClick={() => openOutgoing('sold')}
          >
            <ArrowUpCircle className="w-4 h-4" />
            <span>Outgoing</span>
          </button>
        </div>
      </div>

      {error && (
        <div className="sms-desk-flash sms-desk-flash-error" role="alert">
          <AlertCircle className="w-4 h-4 shrink-0" />
          <span>{error}</span>
        </div>
      )}

      <section className="sms-desk-kpi-grid" aria-label="Summary">
        <button
          type="button"
          className={`sms-desk-kpi-card${movementFilter === 'all' ? ' is-active' : ''}`}
          onClick={() => setMovementFilter('all')}
        >
          <div className="sms-desk-kpi-icon sms-desk-kpi-icon--violet">
            <Boxes className="w-4 h-4" />
          </div>
          <div className="sms-desk-kpi-body">
            <div className="sms-desk-kpi-label">all records</div>
            <div className="sms-desk-kpi-value">{stats.records}</div>
          </div>
        </button>

        <button
          type="button"
          className={`sms-desk-kpi-card${movementFilter === 'in' ? ' is-active' : ''}`}
          onClick={() => setMovementFilter('in')}
        >
          <div className="sms-desk-kpi-icon sms-desk-kpi-icon--teal">
            <ArrowDownCircle className="w-4 h-4" />
          </div>
          <div className="sms-desk-kpi-body">
            <div className="sms-desk-kpi-label">incoming</div>
            <div className="sms-desk-kpi-value">{stats.incomingCount}</div>
            <div className="sms-desk-kpi-helper">{stats.totalIn.toLocaleString()} units</div>
          </div>
        </button>

        <button
          type="button"
          className={`sms-desk-kpi-card${movementFilter === 'out' ? ' is-active' : ''}`}
          onClick={() => setMovementFilter('out')}
        >
          <div className="sms-desk-kpi-icon sms-desk-kpi-icon--rose">
            <ArrowUpCircle className="w-4 h-4" />
          </div>
          <div className="sms-desk-kpi-body">
            <div className="sms-desk-kpi-label">outgoing</div>
            <div className="sms-desk-kpi-value">{stats.outgoingCount}</div>
            <div className="sms-desk-kpi-helper">{stats.totalOut.toLocaleString()} units</div>
          </div>
        </button>
      </section>

      <section className="sms-desk-results">
        <div className="sms-desk-results-head">
          <span className="sms-desk-results-count">
            {listedMovements.length} {listedMovements.length === 1 ? 'result' : 'results'}
            {loadingMovements ? ' - refreshing...' : ''}
          </span>
          <div className="sms-desk-results-actions">
            {movementFilter !== 'all' && (
              <button type="button" className="sms-desk-filter-chip" onClick={() => setMovementFilter('all')}>
                Clear filter
                <X size={12} aria-hidden="true" />
              </button>
            )}
            <ExportMenu
              exportingPdf={exportingPdf}
              pdfDisabled={loadingMovements}
              onExportPdf={openExportPdf}
            />
          </div>
        </div>

        {listedMovements.length === 0 && !loadingMovements ? (
          <div className="sms-desk-empty">
            <Inbox className="sms-desk-empty-icon" aria-hidden="true" />
            <p className="sms-desk-empty-title">No store records yet</p>
            <p className="sms-desk-empty-sub">Use Receive or Outgoing to record or confirm stock here.</p>
          </div>
        ) : (
          <MovementsList
            movements={listedMovements}
            loading={loadingMovements && movements.length === 0}
            deletingId={deletingId}
            onSelect={openMovementDetail}
            onDelete={canDeleteRecords ? handleDeleteMovement : undefined}
          />
        )}
      </section>

      <ExportPdfModal
        open={exportPdfOpen}
        exporting={exportingPdf}
        error={exportPdfError}
        onClose={() => {
          if (!exportingPdf) {
            setExportPdfOpen(false);
            setExportPdfError(null);
          }
        }}
        onExport={handleExportPdf}
      />
      {pendingDelete && (
        <WarningConfirmPopup
          title="Warning!"
          message={`Delete ${pendingDelete.productName || pendingDelete.productSku || 'this product'} from warehouse records? This will reverse the stock quantity for this row.`}
          confirmLabel="Delete"
          cancelLabel="Cancel"
          confirming={deletingId === pendingDelete.id}
          onCancel={() => {
            if (!deletingId) setPendingDelete(null);
          }}
          onConfirm={() => {
            void confirmPendingDelete();
          }}
        />
      )}
    </div>
  );
}
