import { memo, useMemo } from 'react';

const OrderDocumentPane = memo(function OrderDocumentPane({ html, fontFamily, className = 'ov-document-wrap' }) {
  const style = useMemo(
    () => (fontFamily ? { '--ov-doc-font-stack': fontFamily } : undefined),
    [fontFamily],
  );

  return (
    <div
      className={className}
      style={style}
      dangerouslySetInnerHTML={{ __html: html || '' }}
    />
  );
});

export default OrderDocumentPane;
