#!/usr/bin/env node
/**
 * Verifica que la documentación no mienta sobre el repositorio:
 *
 *   1. Los enlaces relativos entre documentos apuntan a algo que existe.
 *   2. Las rutas de archivo citadas entre backticks existen (solo en docs vivos:
 *      los specs y planes son historia y citan rutas de su momento).
 *   3. Cada spec declara un header `Estado:`.
 *
 * No comprueba si el contenido es correcto — eso sigue siendo trabajo humano.
 * Sale con código 1 si encuentra algo, para que el CI falle.
 */
import { readFileSync, readdirSync, statSync, existsSync } from 'node:fs';
import { join, dirname, normalize } from 'node:path';

const RAIZ = process.cwd();
const IGNORAR = ['node_modules', 'vendor', '.git', 'public/build', 'storage', '.superpowers', '.agents', '.claude'];

// Los specs y planes son historia congelada: citan rutas que existían cuando se
// escribieron, o que se propusieron y acabaron con otro nombre. Verificarlas ahí
// bloquearía el CI para siempre sin aportar nada. En los docs vivos sí se exige.
const ES_HISTORIA = (ruta) => ruta.includes('docs/superpowers/');
// Solo se verifican rutas que empiecen por un directorio real del repo: así una
// ruta relativa a un paquete (`data/Config.kt`) no cuenta como error.
const DIRS = ['app/', 'config/', 'database/', 'docs/', 'resources/', 'routes/', 'scripts/', 'tests/', 'bootstrap/', '.github/'];

const problemas = [];

function markdowns(dir, salida = []) {
  for (const nombre of readdirSync(dir)) {
    const ruta = join(dir, nombre);
    if (IGNORAR.some((i) => ruta.includes(i))) continue;
    const st = statSync(ruta);
    if (st.isDirectory()) markdowns(ruta, salida);
    else if (nombre.endsWith('.md')) salida.push(ruta);
  }
  return salida;
}

const archivos = markdowns(RAIZ);

for (const archivo of archivos) {
  const texto = readFileSync(archivo, 'utf8');
  const relativo = archivo.replace(RAIZ + '/', '');

  // 1. Enlaces relativos
  for (const m of texto.matchAll(/\[([^\]]*)\]\(([^)\s]+)(?:\s+"[^"]*")?\)/g)) {
    const destino = m[2];
    if (/^(https?:|mailto:|tel:|#)/.test(destino)) continue;
    const limpio = destino.split('#')[0];
    if (!limpio) continue;
    const resuelto = normalize(join(dirname(archivo), limpio));
    if (!existsSync(resuelto)) {
      problemas.push(`${relativo}: enlace roto [${m[1]}] -> ${destino}`);
    }
  }

  // 2. Rutas de archivo citadas entre backticks — solo en documentación viva
  if (!ES_HISTORIA(relativo)) for (const m of texto.matchAll(/`([A-Za-z0-9_\-./]+\.(?:php|vue|js|mjs|cjs|json|md|yml|yaml))`/g)) {
    const ruta = m[1].replace(/^\.\//, '');
    if (!DIRS.some((d) => ruta.startsWith(d))) continue;
    if (!existsSync(join(RAIZ, ruta))) {
      problemas.push(`${relativo}: ruta inexistente \`${ruta}\``);
    }
  }
}

// 3. Headers de estado en los specs
const dirSpecs = join(RAIZ, 'docs/superpowers/specs');
if (existsSync(dirSpecs)) {
  for (const nombre of readdirSync(dirSpecs).filter((f) => f.endsWith('.md'))) {
    const cabecera = readFileSync(join(dirSpecs, nombre), 'utf8').split('\n').slice(0, 12).join('\n');
    if (!/^[-*\s]*\*\*Estado:?\*\*/im.test(cabecera)) {
      problemas.push(`docs/superpowers/specs/${nombre}: sin header **Estado:** en las primeras 12 líneas`);
    }
  }
}

if (problemas.length === 0) {
  console.log(`✓ Documentación verificada: ${archivos.length} archivos, sin problemas.`);
  process.exit(0);
}

console.error(`✗ ${problemas.length} problema(s) en la documentación:\n`);
for (const p of problemas) console.error(`  ${p}`);
console.error('\nCorrige la documentación, o el enlace/ruta que dejó de existir.');
process.exit(1);
