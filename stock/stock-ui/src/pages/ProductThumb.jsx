import React, { useEffect, useRef, useState } from 'react';
import { HiOutlineCamera } from 'react-icons/hi2';

/**
 * Product thumbnail with shimmer skeleton until the image is actually displayed.
 */
export default function ProductThumb({
  src,
  alt = '',
  className = 'prod-desk-thumb',
  size = 14,
}) {
  const [loaded, setLoaded] = useState(false);
  const [failed, setFailed] = useState(false);
  const imgRef = useRef(null);

  useEffect(() => {
    setLoaded(false);
    setFailed(false);
  }, [src]);

  useEffect(() => {
    const img = imgRef.current;
    if (!src || !img) return;
    if (img.complete && img.naturalWidth > 0) {
      setLoaded(true);
    }
  }, [src]);

  if (!src || failed) {
    return (
      <div className={`${className} is-empty`} title="No image">
        <HiOutlineCamera size={size} aria-hidden="true" />
      </div>
    );
  }

  return (
    <div
      className={`${className}${loaded ? ' is-loaded' : ' is-loading'}`}
      aria-busy={!loaded}
      title={loaded ? alt || undefined : 'Loading image…'}
    >
      {!loaded && (
        <span className="prod-desk-thumb-skeleton" aria-hidden="true">
          <span className="prod-desk-bone prod-desk-bone--thumb-fill" />
        </span>
      )}
      <img
        ref={imgRef}
        src={src}
        alt={alt}
        loading="lazy"
        decoding="async"
        className={loaded ? 'is-visible' : ''}
        onLoad={() => setLoaded(true)}
        onError={() => {
          setFailed(true);
          setLoaded(false);
        }}
      />
    </div>
  );
}
