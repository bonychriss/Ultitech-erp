(function (global) {
  function prepareCloneForCapture(clonedDoc) {
    clonedDoc.querySelectorAll('.sheet, .doc-terms-sheet-inner, .sheet-container').forEach(function (node) {
      node.style.minHeight = 'auto';
      node.style.height = 'auto';
      node.style.marginTop = '0';
    });
  }

  function trimCanvasBottomWhitespace(canvas) {
    var ctx = canvas.getContext('2d');
    if (!ctx) return canvas;

    var width = canvas.width;
    var height = canvas.height;
    if (width <= 0 || height <= 0) return canvas;

    var data = ctx.getImageData(0, 0, width, height).data;
    var bottom = height - 1;
    var step = Math.max(1, Math.floor(width / 120));

    outer: for (; bottom >= 0; bottom -= 1) {
      for (var x = 0; x < width; x += step) {
        var i = (bottom * width + x) * 4;
        if (data[i] < 248 || data[i + 1] < 248 || data[i + 2] < 248) {
          break outer;
        }
      }
    }

    var trimmedHeight = bottom + 1;
    if (trimmedHeight <= 0 || trimmedHeight >= height - 8) {
      return canvas;
    }

    var trimmed = document.createElement('canvas');
    trimmed.width = width;
    trimmed.height = trimmedHeight;
    trimmed.getContext('2d').drawImage(canvas, 0, 0, width, trimmedHeight, 0, 0, width, trimmedHeight);
    return trimmed;
  }

  function canvasSliceIsMostlyBlank(tempCanvas) {
    var ctx = tempCanvas.getContext('2d');
    if (!ctx) return true;

    var width = tempCanvas.width;
    var height = tempCanvas.height;
    if (width <= 0 || height <= 0) return true;

    var data = ctx.getImageData(0, 0, width, height).data;
    var stepX = Math.max(1, Math.floor(width / 48));
    var stepY = Math.max(1, Math.floor(height / 48));
    var white = 0;
    var total = 0;

    for (var y = 0; y < height; y += stepY) {
      for (var x = 0; x < width; x += stepX) {
        var i = (y * width + x) * 4;
        total += 1;
        if (data[i] > 248 && data[i + 1] > 248 && data[i + 2] > 248) {
          white += 1;
        }
      }
    }

    return total > 0 && white / total >= 0.985;
  }

  function captureElements(containerId) {
    var wrap = document.getElementById(containerId);
    if (!wrap) return [];

    var selectors = ['.sheet-container', '.spare-sheet', '.truck-sheet', '.truck-sheet-second'];
    var seen = new Set();
    var elements = [];

    selectors.forEach(function (selector) {
      wrap.querySelectorAll(selector).forEach(function (el) {
        if (!seen.has(el) && el.offsetWidth > 0 && el.offsetHeight > 0) {
          seen.add(el);
          elements.push(el);
        }
      });
    });

    if (!elements.length && wrap.offsetWidth > 0 && wrap.offsetHeight > 0) {
      elements.push(wrap);
    }

    return elements;
  }

  function elementToCanvasOptions(el, options) {
    options = options || {};
    return {
      scale: options.scale || 2,
      useCORS: options.stripImages !== true,
      logging: false,
      backgroundColor: '#ffffff',
      scrollX: 0,
      scrollY: -window.scrollY,
      windowWidth: el.scrollWidth,
      windowHeight: el.scrollHeight,
      onclone: function (clonedDoc) {
        prepareCloneForCapture(clonedDoc);
        if (options.stripImages === true) {
          clonedDoc.querySelectorAll('img').forEach(function (img) { img.remove(); });
        }
      },
    };
  }

  function appendRasterCanvasToPdf(doc, canvas, jpegQuality, options) {
    options = options || {};
    var trimmedCanvas = trimCanvasBottomWhitespace(canvas);
    if (!trimmedCanvas.width || !trimmedCanvas.height) {
      throw new Error('Document rendered empty. Refresh the page and try again.');
    }

    var marginMm = options.marginMm == null ? 8 : options.marginMm;
    var pageHmm = doc.internal.pageSize.getHeight();
    var pageWmm = doc.internal.pageSize.getWidth();
    var innerWmm = pageWmm - 2 * marginMm;
    var innerHmm = pageHmm - 2 * marginMm;
    var sliceHeightInPixels = (trimmedCanvas.width * innerHmm) / innerWmm;
    var totalHeightInPixels = trimmedCanvas.height;
    var sourceY = 0;
    var pageNum = 0;

    while (sourceY < totalHeightInPixels) {
      var currentSliceHeight = Math.min(sliceHeightInPixels, totalHeightInPixels - sourceY);
      var tempCanvas = document.createElement('canvas');
      tempCanvas.width = trimmedCanvas.width;
      tempCanvas.height = currentSliceHeight;

      var tempCtx = tempCanvas.getContext('2d');
      tempCtx.fillStyle = '#ffffff';
      tempCtx.fillRect(0, 0, tempCanvas.width, tempCanvas.height);
      tempCtx.drawImage(
        trimmedCanvas,
        0, sourceY, trimmedCanvas.width, currentSliceHeight,
        0, 0, trimmedCanvas.width, currentSliceHeight
      );

      sourceY += currentSliceHeight;

      if (canvasSliceIsMostlyBlank(tempCanvas)) {
        continue;
      }

      var needsNewPage = pageNum > 0
        || (options.startOnNewPage === true && pageNum === 0 && doc.internal.getNumberOfPages() > 0);
      if (needsNewPage) {
        doc.addPage();
      }

      var sliceDataUrl = tempCanvas.toDataURL('image/jpeg', jpegQuality);
      var destHeightMm = (currentSliceHeight * innerWmm) / trimmedCanvas.width;
      doc.addImage(sliceDataUrl, 'JPEG', marginMm, marginMm, innerWmm, destHeightMm);
      pageNum += 1;
    }
  }

  async function appendElementsToPdf(doc, elements, jpegQuality, renderCanvas, options) {
    options = options || {};
    for (var index = 0; index < elements.length; index += 1) {
      var el = elements[index];
      if (!el) continue;
      var canvas = await renderCanvas(el);
      appendRasterCanvasToPdf(doc, canvas, jpegQuality, {
        marginMm: options.marginMm,
        startOnNewPage: index > 0,
      });
    }
  }

  global.SalesDocumentPdfCapture = {
    captureElements: captureElements,
    elementToCanvasOptions: elementToCanvasOptions,
    appendRasterCanvasToPdf: appendRasterCanvasToPdf,
    appendElementsToPdf: appendElementsToPdf,
    prepareCloneForCapture: prepareCloneForCapture,
  };
}(window));
