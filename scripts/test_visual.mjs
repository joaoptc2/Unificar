/**
 * Teste visual da aparência.
 *
 * Fotografa a galeria de superfícies (Administração > Aparência > Galeria)
 * nos temas claro e escuro e compara com as imagens de referência guardadas
 * em scripts/visual-ref/. Falha quando a diferença passa do limite.
 *
 * É o que impede uma mudança de CSS de quebrar uma superfície que ninguém
 * abriu durante o desenvolvimento — o menu no tema escuro, o selo de estado,
 * a tabela de vencidos.
 *
 * Uso:
 *   node scripts/test_visual.mjs                  compara com a referência
 *   node scripts/test_visual.mjs --atualizar      grava novas referências
 *
 * Variáveis: BASE (padrão http://127.0.0.1:8088), COOKIES (arquivo JSON com
 * a sessão), PW_CHROMIUM (caminho do navegador).
 */
import { chromium } from '/opt/node22/lib/node_modules/playwright/index.mjs';
import fs from 'fs';
import zlib from 'zlib';
import path from 'path';
import { fileURLToPath } from 'url';

const AQUI    = path.dirname(fileURLToPath(import.meta.url));
const REF     = path.join(AQUI, 'visual-ref');
const SAIDA   = process.env.SAIDA || path.join(AQUI, '..', '.visual-out');
const BASE    = process.env.BASE || 'http://127.0.0.1:8088';
const BS      = '/usr/share/javascript/bootstrap5';
const ATUALIZAR = process.argv.includes('--atualizar');
// Tolerância: o antialiasing do texto muda um punhado de pixels entre
// execuções. 0,3% pega uma mudança real de layout ou de cor sem acusar ruído.
const LIMITE  = parseFloat(process.env.LIMITE || '0.3');

fs.mkdirSync(REF,   { recursive: true });
fs.mkdirSync(SAIDA, { recursive: true });

const cookiesArq = process.env.COOKIES;
if (!cookiesArq || !fs.existsSync(cookiesArq)) {
  console.error('Informe COOKIES=<arquivo.json> com a sessão de um administrador.');
  process.exit(2);
}

/**
 * Decodifica um PNG de 8 bits (RGB ou RGBA, sem entrelaçamento) — que é o
 * que o Chromium produz. Só com zlib, que já vem no Node.
 *
 * Comparar os BYTES do arquivo não serve: a compressão faz um pixel alterado
 * mudar o fluxo inteiro, então qualquer diferença daria "100%" e a
 * tolerância seria decorativa. Comparando pixel a pixel, a porcentagem passa
 * a significar alguma coisa e o limite consegue absorver o ruído do
 * antialiasing sem deixar passar uma mudança de verdade.
 */
function lerPng(buf) {
  if (buf.readUInt32BE(0) !== 0x89504e47) throw new Error('não é PNG');
  let pos = 8, largura = 0, altura = 0, profundidade = 0, tipoCor = 0;
  const idat = [];
  while (pos < buf.length) {
    const tam = buf.readUInt32BE(pos);
    const tipo = buf.toString('ascii', pos + 4, pos + 8);
    const dados = buf.subarray(pos + 8, pos + 8 + tam);
    if (tipo === 'IHDR') {
      largura = dados.readUInt32BE(0);
      altura = dados.readUInt32BE(4);
      profundidade = dados[8];
      tipoCor = dados[9];
      if (dados[12] !== 0) throw new Error('PNG entrelaçado não é suportado');
    } else if (tipo === 'IDAT') {
      idat.push(dados);
    } else if (tipo === 'IEND') {
      break;
    }
    pos += 12 + tam;   // tamanho + tipo + dados + CRC
  }
  if (profundidade !== 8 || (tipoCor !== 2 && tipoCor !== 6)) {
    throw new Error(`PNG não suportado (profundidade ${profundidade}, tipo ${tipoCor})`);
  }

  const canais = tipoCor === 6 ? 4 : 3;
  const bruto = zlib.inflateSync(Buffer.concat(idat));
  const passo = largura * canais;
  const saida = Buffer.alloc(altura * passo);

  // Desfaz os filtros por linha (PNG guarda cada linha filtrada).
  for (let y = 0; y < altura; y++) {
    const filtro = bruto[y * (passo + 1)];
    const linha = bruto.subarray(y * (passo + 1) + 1, y * (passo + 1) + 1 + passo);
    const destino = saida.subarray(y * passo, (y + 1) * passo);
    for (let x = 0; x < passo; x++) {
      const a = x >= canais ? destino[x - canais] : 0;                    // esquerda
      const b = y > 0 ? saida[(y - 1) * passo + x] : 0;                   // acima
      const c = (x >= canais && y > 0) ? saida[(y - 1) * passo + x - canais] : 0;
      let v = linha[x];
      switch (filtro) {
        case 0: break;
        case 1: v += a; break;
        case 2: v += b; break;
        case 3: v += (a + b) >> 1; break;
        case 4: {                                                         // Paeth
          const p = a + b - c;
          const pa = Math.abs(p - a), pb = Math.abs(p - b), pc = Math.abs(p - c);
          v += (pa <= pb && pa <= pc) ? a : (pb <= pc ? b : c);
          break;
        }
        default: throw new Error('filtro PNG desconhecido: ' + filtro);
      }
      destino[x] = v & 0xff;
    }
  }
  return { largura, altura, canais, dados: saida };
}

/**
 * Diferença entre dois PNG, em % de PIXELS distintos.
 * $tolCanal absorve o antialiasing: uma variação de 1 ou 2 níveis num canal
 * não conta como pixel diferente.
 */
function diferenca(bufA, bufB, tolCanal = 8) {
  let a, b;
  try {
    a = lerPng(bufA);
    b = lerPng(bufB);
  } catch (e) {
    console.log('    (não foi possível decodificar: ' + e.message + ' — comparando bytes)');
    return Buffer.compare(bufA, bufB) === 0 ? 0 : 100;
  }
  if (a.largura !== b.largura || a.altura !== b.altura) {
    console.log(`    (tamanho mudou: ${a.largura}x${a.altura} → ${b.largura}x${b.altura})`);
    return 100;
  }
  const total = a.largura * a.altura;
  let diferentes = 0;
  for (let i = 0; i < total; i++) {
    const o = i * a.canais;
    let mudou = false;
    for (let c = 0; c < a.canais; c++) {
      if (Math.abs(a.dados[o + c] - b.dados[o + c]) > tolCanal) { mudou = true; break; }
    }
    if (mudou) diferentes++;
  }
  return (diferentes / total) * 100;
}

const browser = await chromium.launch({ executablePath: process.env.PW_CHROMIUM });
const ctx = await browser.newContext({ viewport: { width: 1400, height: 1000 } });
// O Bootstrap vem de CDN; em ambiente sem rede, serve o local.
await ctx.route('**cdn.jsdelivr.net/**', async (r) => {
  const u = r.request().url();
  if (u.includes('css/bootstrap.min.css') && fs.existsSync(BS + '/css/bootstrap.min.css')) {
    return r.fulfill({ path: BS + '/css/bootstrap.min.css', contentType: 'text/css' });
  }
  if (u.includes('bootstrap.bundle.min.js') && fs.existsSync(BS + '/js/bootstrap.bundle.min.js')) {
    return r.fulfill({ path: BS + '/js/bootstrap.bundle.min.js', contentType: 'application/javascript' });
  }
  return r.fulfill({ body: '', contentType: u.endsWith('.css') ? 'text/css' : 'application/javascript' });
});
await ctx.addCookies(JSON.parse(fs.readFileSync(cookiesArq, 'utf8')));

const page = await ctx.newPage();
const cenas = [
  { nome: 'superficies-claro',  url: `${BASE}/index.php?m=admin&a=surfaces`, tema: 'claro'  },
  { nome: 'superficies-escuro', url: `${BASE}/index.php?m=admin&a=surfaces`, tema: 'escuro' },
  { nome: 'aparencia',          url: `${BASE}/index.php?m=admin&a=appearance`, tema: 'claro' },
];

let falhas = 0, novas = 0;
for (const cena of cenas) {
  await page.goto(cena.url, { waitUntil: 'networkidle' });
  await page.evaluate((t) => document.documentElement.setAttribute('data-portal-theme', t), cena.tema);
  // Animações e transições paradas: senão a mesma tela rende imagens
  // diferentes a cada execução.
  await page.addStyleTag({ content: '*,*::before,*::after{transition:none!important;animation:none!important;caret-color:transparent!important}' });
  await page.waitForTimeout(600);

  const img = await page.screenshot({ fullPage: true });
  const arqRef = path.join(REF, cena.nome + '.png');

  if (ATUALIZAR || !fs.existsSync(arqRef)) {
    fs.writeFileSync(arqRef, img);
    console.log(`  ${cena.nome.padEnd(22)} referência ${ATUALIZAR ? 'atualizada' : 'criada'}`);
    novas++;
    continue;
  }

  const d = diferenca(fs.readFileSync(arqRef), img);
  if (d > LIMITE) {
    const arqAtual = path.join(SAIDA, cena.nome + '.atual.png');
    fs.writeFileSync(arqAtual, img);
    console.log(`  ${cena.nome.padEnd(22)} MUDOU ${d.toFixed(2)}% (limite ${LIMITE}%) → ${arqAtual}`);
    falhas++;
  } else {
    console.log(`  ${cena.nome.padEnd(22)} igual (${d.toFixed(2)}%)`);
  }
}

await browser.close();

if (novas > 0 && falhas === 0) {
  console.log(`\n${novas} referência(s) gravada(s). Rode de novo para comparar.`);
  process.exit(0);
}
if (falhas > 0) {
  console.log(`\n${falhas} superfície(s) mudaram. Se a mudança é esperada, rode com --atualizar.`);
  process.exit(1);
}
console.log('\nAparência inalterada em todas as superfícies.');
process.exit(0);
