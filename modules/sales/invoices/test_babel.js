const fs = require('fs');
const Babel = require('@babel/core');

const html = fs.readFileSync('c:/xampp/htdocs/public_html/modules/sales/invoices/index.php', 'utf8');
const match = html.match(/<script type="text\/babel">([\s\S]*?)<\/script>/);

if (match && match[1]) {
    try {
        Babel.transformSync(match[1], { presets: ['@babel/preset-react'] });
        console.log('Babel compilation successful!');
    } catch (e) {
        console.error('Babel compilation failed:', e.message);
    }
} else {
    console.log('No babel script found');
}
