import * as THREE from 'three';
import { useMemo, useRef, useState, useEffect } from 'react';
import { CARD_WIDTH, CARD_HEIGHT, GLOBE_RADIUS, LOCATIONS, getClientId } from '../data';
import locationInfoMap from '../locationInfo.json';

interface CardProps {
  index: number;
  position: THREE.Vector3;
  scale?: number;
  userPhoto: string;
  onSelect: (image: string, location: string, info: string) => void;
  onHover?: (info: string) => void;
  onHoverOut?: () => void;
}

const MAX_CONCURRENT_CARD_GENERATIONS = 3;
let activeCardRequests = 0;
let cooldownUntilMs = 0;
let loggedFallbackWarning = false;

interface QueuedCardTask {
  run: () => void;
  cancelled: boolean;
}

const cardTaskQueue: QueuedCardTask[] = [];

function pumpCardQueue(): void {
  if (activeCardRequests >= MAX_CONCURRENT_CARD_GENERATIONS) return;
  const now = Date.now();
  if (cooldownUntilMs > now) {
    window.setTimeout(pumpCardQueue, cooldownUntilMs - now + 25);
    return;
  }

  while (
    activeCardRequests < MAX_CONCURRENT_CARD_GENERATIONS &&
    cardTaskQueue.length > 0
  ) {
    const next = cardTaskQueue.shift();
    if (!next || next.cancelled) continue;
    next.run();
  }
}

function enqueueCardGeneration(
  fn: (signal: AbortSignal) => Promise<void>
): () => void {
  const controller = new AbortController();
  const task: QueuedCardTask = {
    cancelled: false,
    run: () => {
      if (task.cancelled) {
        pumpCardQueue();
        return;
      }
      activeCardRequests += 1;
      void fn(controller.signal).finally(() => {
        activeCardRequests = Math.max(0, activeCardRequests - 1);
        pumpCardQueue();
      });
    },
  };
  cardTaskQueue.push(task);
  pumpCardQueue();

  return () => {
    task.cancelled = true;
    controller.abort();
  };
}

function buildFallbackInfo(location: string): string {
  const map = locationInfoMap as Record<string, string>;
  const paragraph =
    map[location] ||
    (location === 'Serengeti National Park, Tanzania'
      ? map['Serengeti Park, Tanzania']
      : undefined) ||
    `${location} is an iconic global landmark celebrated for its unforgettable scenery and history.`;
  return `# ${location}\n${paragraph}`;
}

function renderCardPlaceholderCanvas(
  location: string,
  index: number,
  isPending: boolean
): HTMLCanvasElement {
  const canvas = document.createElement('canvas');
  canvas.width = 400;
  canvas.height = 500;
  const ctx = canvas.getContext('2d');
  if (!ctx) return canvas;

  const palettes = [
    ['#EAE7E1', '#D6CFC4'],
    ['#E4E9EC', '#CBD5DC'],
    ['#ECE6E4', '#D9CCC7'],
    ['#E6EAE4', '#CDD6C9'],
  ];
  const [topHex, botHex] = palettes[index % palettes.length];
  const grad = ctx.createLinearGradient(0, 0, 0, 500);
  grad.addColorStop(0, isPending ? '#EDEDED' : topHex);
  grad.addColorStop(1, isPending ? '#DFDFDF' : botHex);
  ctx.fillStyle = grad;
  ctx.fillRect(0, 0, 400, 500);

  ctx.strokeStyle = 'rgba(17, 24, 39, 0.12)';
  ctx.lineWidth = 2;
  ctx.strokeRect(20, 20, 360, 460);

  if (!isPending) {
    ctx.fillStyle = '#111827';
    ctx.textAlign = 'center';
    ctx.font = '600 22px system-ui, -apple-system, sans-serif';

    const words = location.split(/\s+/);
    const lines: string[] = [];
    let current = '';
    for (const word of words) {
      const candidate = current ? `${current} ${word}` : word;
      if (ctx.measureText(candidate).width > 300 && current) {
        lines.push(current);
        current = word;
      } else {
        current = candidate;
      }
    }
    if (current) lines.push(current);

    const startY = 250 - ((lines.length - 1) * 30) / 2;
    lines.forEach((line, idx) => {
      ctx.fillText(line, 200, startY + idx * 30);
    });
  }

  return canvas;
}

function loadTextureFromDataUrl(dataUrl: string): Promise<THREE.Texture | null> {
  return new Promise((resolve) => {
    const img = new Image();
    img.onload = () => {
      const tex = new THREE.Texture(img);
      tex.colorSpace = THREE.SRGBColorSpace;
      tex.minFilter = THREE.LinearMipmapLinearFilter;
      tex.generateMipmaps = true;
      tex.needsUpdate = true;
      resolve(tex);
    };
    img.onerror = () => resolve(null);
    img.src = dataUrl;
  });
}

export default function Card({
  index,
  position,
  scale = 1,
  userPhoto,
  onSelect,
  onHover,
  onHoverOut,
}: CardProps) {
  const meshRef = useRef<THREE.Mesh>(null);
  const [hovered, setHovered] = useState(false);
  const [cardInfo, setCardInfo] = useState<{
    base64: string;
    location: string;
    info: string;
  } | null>(null);

  const [texture, setTexture] = useState<THREE.Texture | null>(null);

  useEffect(() => {
    return () => {
      texture?.dispose();
    };
  }, [texture]);

  useEffect(() => {
    let active = true;
    const defaultLocation = LOCATIONS[index % LOCATIONS.length];

    const replaceTexture = (nextTex: THREE.Texture) => {
      setTexture(nextTex);
    };

    const initialCanvas = renderCardPlaceholderCanvas(defaultLocation, index, true);
    const initialTex = new THREE.CanvasTexture(initialCanvas);
    initialTex.colorSpace = THREE.SRGBColorSpace;
    initialTex.minFilter = THREE.LinearMipmapLinearFilter;
    initialTex.generateMipmaps = true;
    replaceTexture(initialTex);

    const applyFallbackCard = (locName: string, infoText?: string) => {
      if (!active) return;
      const fallbackCanvas = renderCardPlaceholderCanvas(locName, index, false);
      const fallbackDataUrl = fallbackCanvas.toDataURL('image/jpeg', 0.86);
      const fallbackTex = new THREE.CanvasTexture(fallbackCanvas);
      fallbackTex.colorSpace = THREE.SRGBColorSpace;
      fallbackTex.minFilter = THREE.LinearMipmapLinearFilter;
      fallbackTex.generateMipmaps = true;
      replaceTexture(fallbackTex);
      setCardInfo({
        base64: fallbackDataUrl,
        location: locName,
        info: infoText || buildFallbackInfo(locName),
      });
    };

    const cancelQueued = enqueueCardGeneration(async (signal) => {
      try {
        const res = await fetch('/api/generate-location', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'x-client-id': getClientId() },
          body: JSON.stringify({ index, userImageBase64: userPhoto }),
          signal,
        });

        if (res.status === 429) {
          const rawRetry = res.headers.get('retry-after');
          let retryMs = 4000;
          if (rawRetry) {
            const parsedSec = Number(rawRetry);
            if (Number.isFinite(parsedSec)) {
              retryMs = parsedSec * 1000;
            } else {
              const parsedDate = Date.parse(rawRetry);
              if (Number.isFinite(parsedDate)) {
                retryMs = Math.max(0, parsedDate - Date.now());
              }
            }
          }
          cooldownUntilMs = Math.max(
            cooldownUntilMs,
            Date.now() + Math.min(Math.max(retryMs, 2000), 12000)
          );
        }

        const contentType = res.headers.get('content-type') || '';
        if (!res.ok || !contentType.includes('application/json')) {
          if (!loggedFallbackWarning) {
            loggedFallbackWarning = true;
            console.warn(`Card generation returned HTTP ${res.status}; using fallback card.`);
          }
          applyFallbackCard(defaultLocation);
          return;
        }

        const data = await res.json();
        if (!active) return;

        const resolvedLocation =
          typeof data?.location === 'string' && data.location
            ? data.location
            : defaultLocation;
        const resolvedInfo =
          typeof data?.info === 'string' && data.info
            ? data.info
            : buildFallbackInfo(resolvedLocation);

        if (
          data?.success &&
          typeof data.base64 === 'string' &&
          data.base64.startsWith('data:image/')
        ) {
          const loadedTex = await loadTextureFromDataUrl(data.base64);
          if (!active) {
            loadedTex?.dispose();
            return;
          }
          if (loadedTex) {
            setCardInfo({
              base64: data.base64,
              location: resolvedLocation,
              info: resolvedInfo,
            });
            replaceTexture(loadedTex);
            return;
          }
        }

        applyFallbackCard(resolvedLocation, resolvedInfo);
      } catch (e) {
        if ((e as any)?.name === 'AbortError' || !active) return;
        if (!loggedFallbackWarning) {
          loggedFallbackWarning = true;
          console.warn('Card generation degraded to local placeholder:', e);
        }
        applyFallbackCard(defaultLocation);
      }
    });

    return () => {
      active = false;
      cancelQueued();
    };
  }, [index, userPhoto]);

  useEffect(() => {
    if (hovered && cardInfo && onHover) {
      onHover(cardInfo.info);
    }
  }, [cardInfo, hovered, onHover]);

  const rotationQuaternion = useMemo(() => {
    const dummy = new THREE.Object3D();
    dummy.position.copy(position);
    dummy.lookAt(position.clone().multiplyScalar(2));
    return dummy.quaternion.clone();
  }, [position]);

  const geometry = useMemo(() => {
    const width = CARD_WIDTH * scale;
    const height = CARD_HEIGHT * scale;
    const geo = new THREE.PlaneGeometry(width, height, 32, 32);
    const pos = geo.attributes.position;

    for (let i = 0; i < pos.count; i++) {
      const x = pos.getX(i);
      const y = pos.getY(i);

      const theta = x / GLOBE_RADIUS;
      const phi = y / GLOBE_RADIUS;

      const newX = GLOBE_RADIUS * Math.sin(theta) * Math.cos(phi);
      const newY = GLOBE_RADIUS * Math.sin(phi);
      const newZ = GLOBE_RADIUS * Math.cos(theta) * Math.cos(phi) - GLOBE_RADIUS;

      pos.setXYZ(i, newX, newY, newZ);
    }

    geo.computeVertexNormals();
    return geo;
  }, [scale]);

  useEffect(() => {
    return () => {
      geometry.dispose();
    };
  }, [geometry]);

  return (
    <mesh
      position={position}
      quaternion={rotationQuaternion}
      ref={meshRef}
      geometry={geometry}
      onClick={(e) => {
        e.stopPropagation();
        if (cardInfo) {
          onSelect(cardInfo.base64, cardInfo.location, cardInfo.info);
        }
      }}
      onPointerOver={(e) => {
        e.stopPropagation();
        setHovered(true);
        document.body.style.cursor = 'pointer';
        if (cardInfo && onHover) {
          onHover(cardInfo.info);
        }
      }}
      onPointerOut={() => {
        setHovered(false);
        document.body.style.cursor = 'auto';
        if (onHoverOut) {
          onHoverOut();
        }
      }}
    >
      {texture && (
        <meshBasicMaterial
          map={texture}
          side={THREE.DoubleSide}
          toneMapped={false}
        />
      )}
    </mesh>
  );
}
