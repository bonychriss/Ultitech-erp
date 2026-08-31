<?php
// A small standalone script to test React and Babel without any ERP dependencies
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>React Diagnostic Test</title>
    <!-- Use Cloudflare CDN -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/react/18.2.0/umd/react.production.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/react-dom/18.2.0/umd/react-dom.production.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/babel-standalone/7.23.10/babel.min.js"></script>
    <style>
        body { font-family: sans-serif; padding: 20px; }
        .box { padding: 15px; margin: 10px 0; border-radius: 5px; }
        .box.success { background: #dcfce7; border: 1px solid #16a34a; color: #166534; }
        .box.error { background: #fee2e2; border: 1px solid #dc2626; color: #991b1b; }
    </style>
</head>
<body>
    <h2>React & Babel Diagnostic Test</h2>
    <div id="react-root">
        <div class="box" style="background:#f3f4f6; border:1px solid #d1d5db; color:#374151;">
            Loading React... If this message does not change within 3 seconds, React or Babel failed to load!
        </div>
    </div>
    
    <div id="debug-log" style="margin-top: 30px; font-family: monospace; white-space: pre-wrap; background: #111; color: #0f0; padding: 15px; display: none;"></div>

    <script>
        var logEl = document.getElementById('debug-log');
        function logMsg(msg) {
            logEl.style.display = 'block';
            logEl.innerText += '[LOG] ' + msg + '\n';
        }
        
        window.addEventListener('error', function (ev) {
            logMsg('GLOBAL ERROR: ' + (ev.message || ev));
        });

        setTimeout(function() {
            var el = document.getElementById('react-root');
            if (el && el.innerHTML.indexOf('Loading React...') !== -1) {
                logMsg('TIMEOUT: 3 seconds elapsed and React has not rendered.');
                logMsg('typeof React = ' + typeof React);
                logMsg('typeof ReactDOM = ' + typeof ReactDOM);
                logMsg('typeof Babel = ' + typeof Babel);
                if (typeof Babel === 'undefined') {
                    logMsg('CONCLUSION: The Babel script from cdnjs.cloudflare.com failed to load. Your network is blocking the CDN.');
                }
            }
        }, 3000);
    </script>

    <script type="text/babel">
        try {
            logMsg('Babel script block executing successfully.');
            const { useState } = React;
            
            function App() {
                const [count, setCount] = useState(0);
                return (
                    <div className="box success">
                        <h3><span style={{color:'green'}}>✔</span> React is working perfectly!</h3>
                        <p>If you see this box, Babel and React are functioning correctly on your network.</p>
                        <button onClick={() => setCount(c => c + 1)} style={{padding:'8px 16px', cursor:'pointer'}}>Click me: {count}</button>
                    </div>
                );
            }
            
            const root = ReactDOM.createRoot(document.getElementById('react-root'));
            root.render(<App />);
            logMsg('React render function called.');
        } catch (e) {
            logMsg('BABEL EXCEPTION: ' + e.message);
        }
    </script>
</body>
</html>
