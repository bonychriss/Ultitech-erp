// Adds data-label attributes to TDs based on corresponding THEAD TH text
// Works for tables with a THEAD present. Used by CSS stacked-table mode on very small screens.
(function(){
  function enhance(table){
    if (!table) return;
    var head = table.tHead; if (!head || !head.rows || !head.rows[0]) return;
    var headers = Array.prototype.map.call(head.rows[0].cells, function(th){
      return (th.textContent || th.innerText || '').trim();
    });
    Array.prototype.forEach.call(table.tBodies, function(tb){
      Array.prototype.forEach.call(tb.rows, function(tr){
        Array.prototype.forEach.call(tr.cells, function(td, idx){
          if (!td.hasAttribute('data-label')) {
            var label = headers[idx] || '';
            if (label) td.setAttribute('data-label', label);
          }
        });
      });
    });
  }
  function run(){
    var tables = document.querySelectorAll('.stacked-table table.data-table');
    for (var i=0;i<tables.length;i++) enhance(tables[i]);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run);
  else run();
})();
