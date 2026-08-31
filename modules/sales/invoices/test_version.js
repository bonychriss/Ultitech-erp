const Babel = require('./node_modules/@babel/standalone/babel.min.js');
const result = Babel.transform('const App = () => <div>Hello</div>;', { presets: ['react'] });
console.log('Output:', result.code);
