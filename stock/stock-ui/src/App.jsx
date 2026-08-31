import React from 'react';
import ProductsList from './pages/ProductsList';
import ProductView from './pages/ProductView';
import ProductCreate from './pages/ProductCreate';
import CategoriesList from './pages/CategoriesList';
import BrandsList from './pages/BrandsList';
import ProductsImport from './pages/ProductsImport';
import UploadsList from './pages/UploadsList';
import UploadsUpload from './pages/UploadsUpload';
import SuppliersList from './pages/SuppliersList';
import SupplierAdd from './pages/SupplierAdd';
import SupplierEdit from './pages/SupplierEdit';
import Dashboard from './pages/Dashboard';
import Catalogue from './pages/Catalogue';
import StockReport from './pages/StockReport';
import Replenishment from './pages/Replenishment';
import Movements from './pages/Movements';
import WarehousesComingSoon from './pages/WarehousesComingSoon';
import ShipmentsList from './pages/ShipmentsList';
import ShipmentCreate from './pages/ShipmentCreate';
import ShipmentEdit from './pages/ShipmentEdit';
import ShipmentView from './pages/ShipmentView';
import PurchasesList from './pages/PurchasesList';
import PurchaseCreate from './pages/PurchaseCreate';
import PurchaseEdit from './pages/PurchaseEdit';
import PurchaseReceive from './pages/PurchaseReceive';
import SalesCatalogue from './pages/SalesCatalogue';
import Settings from './pages/Settings';

export default function App({ page = 'dashboard', data = {} }) {
  switch (page) {
    case 'products-list':
      return <ProductsList data={data} />;
    case 'product-view':
      return <ProductView data={data} />;
    case 'product-create':
      return <ProductCreate data={data} />;
    case 'categories-list':
      return <CategoriesList data={data} />;
    case 'brands-list':
      return <BrandsList data={data} />;
    case 'products-import':
      return <ProductsImport data={data} />;
    case 'uploads-list':
      return <UploadsList data={data} />;
    case 'uploads-upload':
      return <UploadsUpload data={data} />;
    case 'suppliers-list':
      return <SuppliersList data={data} />;
    case 'supplier-add':
      return <SupplierAdd data={data} />;
    case 'supplier-edit':
      return <SupplierEdit data={data} />;
    case 'catalogue':
      return <Catalogue data={data} />;
    case 'reports-stock':
      return <StockReport data={data} />;
    case 'reports-replenishment':
      return <Replenishment data={data} />;
    case 'stock-movements':
      return <Movements data={data} />;
    case 'warehouses-coming-soon':
      return <WarehousesComingSoon data={data} />;
    case 'shipments-list':
      return <ShipmentsList data={data} />;
    case 'shipment-create':
      return <ShipmentCreate data={data} />;
    case 'shipment-edit':
      return <ShipmentEdit data={data} />;
    case 'shipment-view':
      return <ShipmentView data={data} />;
    case 'purchases-list':
      return <PurchasesList data={data} />;
    case 'purchases-create':
      return <PurchaseCreate data={data} />;
    case 'purchases-edit':
      return <PurchaseEdit data={data} />;
    case 'purchases-receive':
      return <PurchaseReceive data={data} />;
    case 'sales-catalogue':
      return <SalesCatalogue data={data} />;
    case 'settings':
      return <Settings data={data} />;
    case 'dashboard':
    default:
      return <Dashboard data={data} />;
  }
}
