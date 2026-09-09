const { spawnSync } = require('child_process');
const path = require('path');
const { updateServerUrl } = require('./update-config.cjs');

const root = path.join(__dirname, '..');

function run(command, args, options = {}) {
  const result = spawnSync(command, args, {
    cwd: root,
    stdio: 'inherit',
    shell: true,
    ...options
  });

  if (result.status !== 0) {
    process.exit(result.status ?? 1);
  }
}

console.log(`BCUT update server: ${updateServerUrl}`);
console.log('Building web app...');
run('npm', ['run', 'build']);

console.log('Packaging Windows installer...');
run('npx', [
  'electron-builder',
  '--win',
  'nsis',
  '--config.publish.provider=generic',
  `--config.publish.url=${updateServerUrl}`
]);
