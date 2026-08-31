const fs = require('fs');
const esbuild = require('esbuild');

let code = fs.readFileSync('modules/sales/orders/create.php', 'utf8');

// Compile the jsx logic
const matches = [...code.matchAll(/<script type="text\/babel">([\s\S]*?)<\/script>/g)];

let i = 1;
for (const m of matches) {
    const res = esbuild.transformSync(m[1], { loader: 'jsx' });
    code = code.replace(m[0], '<script>' + res.code + '</script>');
    i++;
}

// Remove the babel standalone script
code = code.replace(/<script src="[^"]*babel\.min\.js"><\/script>/g, '');

fs.writeFileSync('modules/sales/orders/create.php', code);
console.log('Successfully injected compiled JS into create.php!');
