const JsConfuser = require('js-confuser');
const fs = require('fs-extra');
const path = require('path');

async function obfuscateDir(src, dest) {
    await fs.ensureDir(dest);
    const files = await fs.readdir(src);

    for (const file of files) {
        const srcPath = path.join(src, file);
        const destPath = path.join(dest, file);
        const stat = await fs.stat(srcPath);

        if (stat.isDirectory()) {
            const folderName = path.basename(srcPath);
            if (folderName === 'node_modules' || folderName === '.git' || folderName === path.basename(dest)) {
                continue;
            }
            await obfuscateDir(srcPath, destPath);
        } else if (file.endsWith('.js')) {
            if (file === 'obfuscate.js') continue;

            console.log(`Obfuscating: ${srcPath}`);
            const code = await fs.readFile(srcPath, 'utf-8');
            try {
                const result = await JsConfuser.obfuscate(code, {
                    target: 'node',
                    preset: 'high',
                    stringConcealing: true,
                    compact: true,
                });
                
                // Handle both string and object return types
                const output = (typeof result === 'object' && result.code) ? result.code : result;
                await fs.writeFile(destPath, output);
            } catch (err) {
                console.error(`[ERROR] Failed to obfuscate ${srcPath}:`, err.message);
                await fs.copy(srcPath, destPath);
            }
        } else {
            await fs.copy(srcPath, destPath);
        }
    }
}

const args = process.argv.slice(2);
const inputDir = args[0] || '.';
const outputDir = args[1] || './dist';

obfuscateDir(path.resolve(inputDir), path.resolve(outputDir))
    .then(() => console.log('Obfuscation completed.'))
    .catch(err => {
        console.error('Obfuscation failed:', err);
        process.exit(1);
    });
