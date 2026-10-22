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

  function collectAvoidBands(rootEl, canvas) {
    if (!rootEl || !canvas || !canvas.width || !canvas.height) return [];

    var restored = [];
    rootEl.querySelectorAll('.sheet, .doc-terms-sheet-inner, .sheet-container').forEach(function (node) {
      restored.push([node, node.style.minHeight, node.style.height]);
      node.style.minHeight = 'auto';
      node.style.height = 'auto';
    });

    try {
      var rootRect = rootEl.getBoundingClientRect();
      var cssH = Math.max(1, rootEl.scrollHeight, rootEl.offsetHeight, rootRect.height);
      var scaleY = canvas.height / cssH;
      var bands = [];
      var seen = new Set();

      function pushEl(el) {
        if (!el || seen.has(el)) return;
        seen.add(el);
        var r = el.getBoundingClientRect();
        var top = Math.round((r.top - rootRect.top) * scaleY);
        var bottom = Math.round((r.bottom - rootRect.top) * scaleY);
        if (bottom - top < 2) return;
        if (bottom <= 1 || top >= canvas.height - 1) return;
        bands.push({
          top: Math.max(0, top),
          bottom: Math.min(canvas.height, bottom),
        });
      }

      rootEl.querySelectorAll(
        '.o-table tbody tr, .o-table thead tr, .items-table tbody tr, .items-table thead tr, .product-grid-item, .catalog-item'
      ).forEach(pushEl);

      rootEl.querySelectorAll('.o-totals-table').forEach(function (table) {
        var wrap = table.parentElement && table.parentElement.tagName === 'DIV'
          ? table.parentElement
          : table;
        var bottomEl = wrap;
        var sib = wrap.nextElementSibling;
        while (sib) {
          if (
            (sib.classList && sib.classList.contains('quot-bank-details'))
            || (sib.classList && sib.classList.contains('document-footer-closing'))
            || (sib.classList && sib.classList.contains('avoid-break'))
          ) {
            bottomEl = sib;
            sib = sib.nextElementSibling;
            continue;
          }
          break;
        }
        var topR = wrap.getBoundingClientRect();
        var botR = bottomEl.getBoundingClientRect();
        var top = Math.round((topR.top - rootRect.top) * scaleY);
        var bottom = Math.round((botR.bottom - rootRect.top) * scaleY);
        if (bottom - top >= 2 && bottom > 1 && top < canvas.height - 1) {
          bands.push({
            top: Math.max(0, top),
            bottom: Math.min(canvas.height, bottom),
          });
        }
      });

      rootEl.querySelectorAll(
        '.invoice-header, .sheet-header, .doc-terms-section, .terms-block, .signature-block, .quot-bank-details, .document-footer-closing'
      ).forEach(pushEl);

      return bands.sort(function (a, b) { return a.top - b.top || a.bottom - b.bottom; });
    } finally {
      restored.forEach(function (entry) {
        entry[0].style.minHeight = entry[1];
        entry[0].style.height = entry[2];
      });
    }
  }

  function collectSafeBreakYs(rootEl, canvas) {
    return collectAvoidBands(rootEl, canvas).map(function (b) { return b.bottom; });
  }

  function pickSliceEnd(sourceY, maxEnd, bands) {
    if (!Array.isArray(bands) || !bands.length) return maxEnd;

    var cutBand = null;
    for (var i = 0; i < bands.length; i += 1) {
      var band = bands[i];
      if (band.bottom <= sourceY + 1) continue;
      if (band.top >= maxEnd - 1) break;
      if (band.top < maxEnd - 1 && band.bottom > maxEnd + 1) {
        cutBand = band;
        break;
      }
    }
    if (!cutBand) return maxEnd;

    var bestBottom = 0;
    for (var j = 0; j < bands.length; j += 1) {
      var bottom = bands[j].bottom;
      if (bottom > sourceY + 2 && bottom <= cutBand.top + 1 && bottom <= maxEnd) {
        bestBottom = bottom;
      }
    }
    if (bestBottom > sourceY) return bestBottom;
    if (cutBand.top - sourceY > 8) return cutBand.top;
    return maxEnd;
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
    var rawBands = Array.isArray(options.bands)
      ? options.bands
      : (Array.isArray(options.breakYs) ? options.breakYs.map(function (y) { return { top: y, bottom: y }; }) : []);
    var usableBands = rawBands
      .map(function (b) {
        return {
          top: typeof b === 'number' ? b : Number(b.top) || 0,
          bottom: typeof b === 'number' ? b : Number(b.bottom) || 0,
        };
      })
      .filter(function (b) { return b.bottom > b.top && b.bottom > 0 && b.top < totalHeightInPixels; })
      .map(function (b) {
        return {
          top: Math.max(0, b.top),
          bottom: Math.min(totalHeightInPixels, b.bottom),
        };
      })
      .sort(function (a, b) { return a.top - b.top || a.bottom - b.bottom; });
    var sourceY = 0;
    var pageNum = 0;

    while (sourceY < totalHeightInPixels) {
      var hardEnd = Math.min(sourceY + sliceHeightInPixels, totalHeightInPixels);
      var sliceEnd = pickSliceEnd(sourceY, hardEnd, usableBands);
      var currentSliceHeight = Math.max(1, sliceEnd - sourceY);
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
        bands: collectAvoidBands(el, canvas),
      });
    }
  }

  global.SalesDocumentPdfCapture = {
    captureElements: captureElements,
    elementToCanvasOptions: elementToCanvasOptions,
    collectAvoidBands: collectAvoidBands,
    collectSafeBreakYs: collectSafeBreakYs,
    appendRasterCanvasToPdf: appendRasterCanvasToPdf,
    appendElementsToPdf: appendElementsToPdf,
    prepareCloneForCapture: prepareCloneForCapture,
  };
}(window));
