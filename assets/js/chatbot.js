(function(){
  var guides = window.CHATBOT_LOCAL_GUIDES = [
    {id:'overview',title:'System Overview',answer:'This Payment Voucher System lets employees create vouchers, route approvals (Dept Manager, Finance Checked By, GM), and finalize them. Statuses: Draft, Pending, Posted, Paid.'},
    {id:'create_voucher',title:'Creating a Voucher',answer:'Go to Create Voucher, fill payee, description, add items with Payment Type, Budget Type, Amount. Select Dept Manager + Checked By then submit or save Draft.'},
    {id:'budget_types',title:'Budget Types',answer:'Operational Expenses; Procurement & Supplies; Employee Costs; Sales & Marketing; Logistics & Delivery; Administration & Management; Projects & Capital Expenditure (CAPEX); Financial Obligations; Tax & Compliance; Others / Miscellaneous.'},
    {id:'approvals',title:'Approval Flow',answer:'Employee creates → Department Manager → Finance (Checked By) → General Manager final approval → Posted → Paid.'},
    {id:'voucher_statuses',title:'Voucher Statuses',answer:'Draft (incomplete) → Pending (submitted) → Posted (accounting recorded) → Paid (funds disbursed).'},
    {id:'reports',title:'Reports',answer:'Admins filter vouchers by date/status/budget. Export for accounting.'},
    {id:'attachments',title:'Attachments',answer:'Match Supporting Documents count with uploaded invoice/receipt references.'},
    {id:'notifications',title:'Notifications',answer:'Bell shows system events, messages icon for direct communication. Badges = unread.'},
    {id:'drafts',title:'Drafts',answer:'Use the disk icon to save progress; later open draft and submit.'},
    {id:'security',title:'Security',answer:'Use strong password; log out; keep financial data internal.'}
  ];

  function el(tag, cls){ var e=document.createElement(tag); if(cls) e.className=cls; return e; }
  var launcherIconHtml = '<span class="chatbot-launcher-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a4 4 0 0 1-4 4H7l-4 4V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z"/></svg></span>';
  function ensureUI(){
    var launcher = document.getElementById('chatbotLauncher');
    if(!launcher) {
      launcher = el('button','chatbot-launcher'); 
      launcher.id='chatbotLauncher'; 
      launcher.type='button'; 
      launcher.setAttribute('aria-label','Help Assistant'); 
      launcher.title = 'Help';
      launcher.innerHTML=launcherIconHtml;
      document.body.appendChild(launcher);
    } else if (!launcher.querySelector('.chatbot-launcher-icon') || launcher.querySelector('.robot-container')) {
      launcher.innerHTML = launcherIconHtml;
    }
    
    var panel = document.getElementById('chatbotPanel');
    if(!panel) {
      panel = el('div','chatbot-panel hidden'); 
      panel.id='chatbotPanel';
      var header = el('div','chatbot-header');
      var h3 = el('h3'); h3.textContent='Help Assistant';
      var closeBtn = el('button','chatbot-close-btn'); closeBtn.type='button'; closeBtn.innerHTML='&#10005;'; closeBtn.onclick=function(){ panel.classList.add('hidden'); };
      header.appendChild(h3); header.appendChild(closeBtn);
      var body = el('div','chatbot-body'); body.id='chatbotBody';
      var footer = el('div','chatbot-footer');
      var input = el('input'); input.id='chatbotInput'; input.placeholder='Ask anything (e.g. create voucher)'; input.setAttribute('aria-label','Chatbot question');
      var sendBtn = el('button'); sendBtn.textContent='Ask'; sendBtn.type='button';
      footer.appendChild(input); footer.appendChild(sendBtn);
      panel.appendChild(header); panel.appendChild(body); panel.appendChild(footer);
      document.body.appendChild(panel);

      sendBtn.onclick=submit;
      input.addEventListener('keydown', function(e){ if(e.key==='Enter'){ submit(); }});
    }

    // Always bind click handler to launcher
    if(!launcher.dataset.bound) {
      launcher.dataset.bound = '1';
      launcher.onclick=function(e){ 
        e.preventDefault(); 
        e.stopPropagation();
        var p = document.getElementById('chatbotPanel');
        if(p) { 
          p.classList.toggle('hidden'); 
          if(!p.classList.contains('hidden')){ 
            var inp = document.getElementById('chatbotInput');
            if(inp) inp.focus(); 
            var body = document.getElementById('chatbotBody');
            if(body && body.children.length===0) intro(); 
          }
        }
      };
    }
  }

  function intro(){
    postBot('Hi! I can explain vouchers, approvals, budget types, statuses, reports, & more. Try clicking a suggestion or ask a question.');
    renderSuggestions(['create voucher','budget types','approvals','statuses','reports']);
  }
  // Expose intro globally for bootstrap script
  window.intro = intro;
  function renderSuggestions(list){
    var body=document.getElementById('chatbotBody'); if(!body) return;
    var wrap=el('div','chatbot-suggestions');
    list.forEach(function(txt){ var b=el('button'); b.textContent=txt; b.onclick=function(){ query(txt); }; wrap.appendChild(b); });
    body.appendChild(wrap); body.scrollTop=body.scrollHeight;
  }
  function postUser(msg){ var body=document.getElementById('chatbotBody'); var d=el('div','chatbot-msg user'); d.textContent=msg; body.appendChild(d); body.scrollTop=body.scrollHeight; }
  function postBot(msg){ var body=document.getElementById('chatbotBody'); var d=el('div','chatbot-msg bot'); d.textContent=msg; body.appendChild(d); body.scrollTop=body.scrollHeight; }

  function localSearch(q){
    q=q.toLowerCase(); var scored=[];
    guides.forEach(function(g){
      var s=0; if(g.title.toLowerCase().indexOf(q)>-1) s+=3; if(g.answer.toLowerCase().indexOf(q)>-1) s+=1; q.split(/\s+/).forEach(function(t){ if(t && g.answer.toLowerCase().indexOf(t)>-1) s+=1; });
      if(s>0) scored.push({score:s,guide:g});
    });
    scored.sort(function(a,b){ return b.score - a.score; });
    return scored.slice(0,3).map(function(x){ return x.guide; });
  }

  function query(q){
    if (q.toLowerCase() === 'open ai assistant') {
      var sidebarLink = document.querySelector('a[href*="ai_assistant.php"]');
      if (sidebarLink) {
        var href = sidebarLink.getAttribute('href');
        if (href) {
          window.location.href = href;
          return;
        }
      }
      var isAd = window.location.pathname.includes('/admin/');
      var isEmp = window.location.pathname.includes('/employee/');
      var dest = 'employee/ai_assistant.php';
      if (isAd) {
        dest = 'ai_assistant.php';
      } else if (isEmp) {
        dest = 'ai_assistant.php';
      } else {
        dest = 'employee/ai_assistant.php';
      }
      window.location.href = dest;
      return;
    }
    postUser(q);
    // Attempt remote API then fallback
    var apiUrl = window.CHATBOT_API_ENDPOINT || (window.location.pathname.includes('/admin/') || window.location.pathname.includes('/employee/') ? '../chatbot_api.php' : 'chatbot_api.php');
    fetch(apiUrl+'?q='+encodeURIComponent(q))
      .then(function(r){ return r.ok?r.json():Promise.reject(); })
      .then(function(json){ if(json && json.results && json.results.length){ handleResults(json.results); } else { fallback(); } })
      .catch(fallback);

    function fallback(){ var r=localSearch(q); if(r.length){ handleResults(r); } else { postBot('I did not find a match. Try keywords like "create voucher", "approvals", "budget types".'); renderSuggestions(['create voucher','approvals','budget types']); } }
  }
  function handleResults(arr){
    arr.forEach(function(g){ postBot(g.title+': '+g.answer); });
    if(arr.length>0){ 
      var suggestions = arr.map(function(g){ return g.title.toLowerCase(); }).slice(0,5);
      var hasAi = arr.some(function(g){ return g.id === 'ai_fallback'; });
      if (hasAi) {
        suggestions.push('open ai assistant');
      }
      renderSuggestions(suggestions); 
    }
  }
  function submit(){ var inp=document.getElementById('chatbotInput'); if(!inp) return; var v=inp.value.trim(); if(!v) return; inp.value=''; query(v); }

  // Lazy init after DOM ready
  if(document.readyState==='loading'){ document.addEventListener('DOMContentLoaded', ensureUI); } else { ensureUI(); }
})();