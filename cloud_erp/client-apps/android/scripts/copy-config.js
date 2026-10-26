import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const rootDir = path.join(__dirname, '..');
const configPath = path.join(rootDir, 'config.json');
const examplePath = path.join(rootDir, '..', 'shared', 'config.example.json');

if (!fs.existsSync(configPath)) {
  fs.copyFileSync(examplePath, configPath);
  console.log('Created android/config.json from shared example.');
}

const config = JSON.parse(fs.readFileSync(configPath, 'utf8'));
const capacitorConfigPath = path.join(rootDir, 'capacitor.config.json');
const capacitorConfig = JSON.parse(fs.readFileSync(capacitorConfigPath, 'utf8'));

const activeUrl = config.useLocalDev ? config.localDevUrl : config.startUrl;
capacitorConfig.appName = config.appName || capacitorConfig.appName;
capacitorConfig.server.url = activeUrl;
capacitorConfig.server.cleartext = Boolean(config.useLocalDev);

fs.writeFileSync(capacitorConfigPath, `${JSON.stringify(capacitorConfig, null, 2)}\n`);
console.log(`Capacitor server URL set to ${activeUrl}`);
