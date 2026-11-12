import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { parse, parseExpression } from '@babel/parser';

const root = fileURLToPath(new URL('../',import.meta.url));
const errors = [], used = new Set(), literals = new Set(), dynamicPrefixes = new Set();
const intentional = /^(?:KotTraLuy|Telegram|English|EN|USD|KHR|JSON|A|B|ខ្មែរ)$/;
function dictionary(name) {
  const raw=fs.readFileSync(path.join(root,'src/locales',name+'.json'),'utf8');
  const ast=parseExpression(raw), keys=new Set();
  for(const property of ast.properties) {
    const key=property.key.value;
    if(keys.has(key)) errors.push(`${name}: duplicate key ${key}`);
    keys.add(key);
  }
  return JSON.parse(raw);
}
const en=dictionary('en'), km=dictionary('km'), sources=dictionary('sourceKeys');
const tokens=value=>[...value.matchAll(/\{(\w+)\}/g)].map(match=>match[1]).sort().join(',');
for(const [key,value] of Object.entries(en)) {
  if(!km[key]?.trim()) errors.push(`km: missing ${key}`);
  else {
    if(tokens(value)!==tokens(km[key])) errors.push(`km: interpolation mismatch ${key}`);
    if(/\?{2,}|\ufffd/.test(km[key])) errors.push(`km: corrupted translation ${key}`);
    if(value===km[key]&&!intentional.test(value)&&value.replace(/\{\w+\}/g,'').trim()!=='') errors.push(`km: untranslated English ${key}`);
    if(!/[\u1780-\u17ff]/u.test(km[key])&&!intentional.test(km[key])&&km[key].replace(/\{\w+\}/g,'').trim()!=='') errors.push(`km: no Khmer translation ${key}`);
  }
}
for(const key of Object.keys(km)) if(!Object.hasOwn(en,key)) errors.push(`en: missing ${key}`);
for(const [source,key]of Object.entries(sources)) if(!Object.hasOwn(en,key)) errors.push(`sourceKeys: ${source} points to missing ${key}`);

function walk(node,file,parent) {
  if(!node || typeof node!=='object') return;
  if(node.type==='StringLiteral') {
    literals.add(node.value);
    if(Object.hasOwn(en,node.value)) used.add(node.value);
    if(sources[node.value]) used.add(sources[node.value]);
  }
  if(node.type==='TemplateLiteral'&&parent?.type==='CallExpression') {
    const prefix=node.quasis[0]?.value.cooked;
    if(prefix&&/^[\w]+\./.test(prefix)) dynamicPrefixes.add(prefix);
  }
  if(node.type==='CallExpression'&&['tr','translate','text','uiLabel'].includes(node.callee?.name)&&node.arguments[0]?.type==='StringLiteral') {
    const key=node.arguments[0].value;
    if(!en[key]&&!sources[key]&&!intentional.test(key)) errors.push(`${file}:${node.loc.start.line}: missing ${key}`);
  }
  if(node.type==='JSXText') {
    const value=node.value.trim();
    if(/[a-z]{2}/i.test(value)&&!intentional.test(value)) errors.push(`${file}:${node.loc.start.line}: hardcoded text ${value}`);
  }
  if(node.type==='JSXAttribute'&&['title','placeholder','label','description','copy','breadcrumb','breadcrumbTitle','aria-label','alt'].includes(node.name?.name)&&node.value?.type==='StringLiteral') {
    const value=node.value.value;
    if(/[a-z]{2}/i.test(value)&&!intentional.test(value)) errors.push(`${file}:${node.loc.start.line}: hardcoded ${node.name.name}: ${value}`);
  }
  for(const [key,value] of Object.entries(node)) {
    if(key==='loc') continue;
    if(Array.isArray(value)) value.forEach(child=>walk(child,file,node));
    else if(value&&typeof value==='object') walk(value,file,node);
  }
}
function scan(directory) {
  for(const entry of fs.readdirSync(directory,{withFileTypes:true})) {
    const file=path.join(directory,entry.name);
    if(entry.isDirectory()&&entry.name!=='locales') scan(file);
    else if(/\.(?:js|jsx)$/.test(entry.name)) walk(parse(fs.readFileSync(file,'utf8'),{sourceType:'module',plugins:['jsx','importAttributes']}),path.relative(root,file));
  }
}
scan(path.join(root,'src'));
const unused=Object.keys(en).filter(key=>!used.has(key)&&![...dynamicPrefixes].some(prefix=>key.startsWith(prefix)));
// Alias entries remain supported for backend/UI enum mappings; report candidates for review.
if(process.argv.includes('--report')) {
  const report={keys:Object.keys(en).length,errors,unusedCandidates:unused,dynamicPrefixes:[...dynamicPrefixes].sort(),intentionalUntranslated:['KotTraLuy','Telegram','English / ខ្មែរ language selectors','USD','KHR','original business records']};
  fs.writeFileSync(path.join(root,'scripts/i18n-audit.json'),JSON.stringify(report,null,2)+'\n');
}
if(errors.length) { console.error(errors.join('\n')); process.exitCode=1; }
else console.log(`i18n passed: ${Object.keys(en).length} matching keys, valid Khmer, matching interpolation, no duplicate keys or hardcoded JSX UI. ${unused.length} unused candidates${process.argv.includes('--report')?' reported in scripts/i18n-audit.json':''}.`);
