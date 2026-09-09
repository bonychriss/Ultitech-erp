const { spawnSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const root = path.join(__dirname, '..');
const androidDir = path.join(root, 'android');
const outputDir = path.join(root, 'release', 'android');
const isWindows = process.platform === 'win32';
const gradlew = isWindows ? 'gradlew.bat' : './gradlew';

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

function copyApk(variant) {
  const apkDir = path.join(androidDir, 'app', 'build', 'outputs', 'apk', variant);
  if (!fs.existsSync(apkDir)) {
    console.error(`APK output folder not found: ${apkDir}`);
    process.exit(1);
  }

  const apkFile = fs.readdirSync(apkDir).find((name) => name.endsWith('.apk'));
  if (!apkFile) {
    console.error(`No APK found in ${apkDir}`);
    process.exit(1);
  }

  fs.mkdirSync(outputDir, { recursive: true });
  const targetName = variant === 'release' ? 'BCUT.apk' : 'BCUT-debug.apk';
  const targetPath = path.join(outputDir, targetName);
  fs.copyFileSync(path.join(apkDir, apkFile), targetPath);
  console.log(`\nAPK copied to: ${targetPath}`);
}

const variant = process.argv.includes('--debug') ? 'debug' : 'release';

console.log('Building web app...');
run('npm', ['run', 'build']);

console.log('Syncing Capacitor Android project...');
run('npx', ['cap', 'sync', 'android']);

if (!fs.existsSync(path.join(androidDir, gradlew.replace('./', '')))) {
  console.error('Android project not found. Run: npm run android:add');
  process.exit(1);
}

console.log(`Building Android ${variant} APK...`);
run(gradlew, [`assemble${variant === 'release' ? 'Release' : 'Debug'}`], { cwd: androidDir });

copyApk(variant);
