(() => {
const $ = selector => document.querySelector(selector);
const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
const slug = value => String(value).normalize('NFD').replace(/[̀-ͯ]/g,'').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'').slice(0,80);
const norm = value => String(value ?? '').normalize('NFD').replace(/[̀-ͯ]/g,'').toLocaleLowerCase().trim();
const splitLines = value => [...new Set(String(value).split(/[\n,]+/).map(item=>item.trim()).filter(Boolean))];
const safeImage = url => Boolean(url) && (/^https:\/\//i.test(url) || /^\/(?:uploads\/(?:categories|products|campaigns|brands)\/|api\/auth\/serve-upload\?)/.test(url)) && !/[\x00-\x1f\\]/.test(url) && !url.includes('..');
let categories = [], families = [], tagConversion = {pending:0,skipped:[]}, editingId = '', pendingUploads = 0, originalChildIds = [];
const selected = new Set();
async function api(action, {method='GET', body} = {}) {
  const token = localStorage.getItem('smartisp.adminToken') || sessionStorage.getItem('smartisp.adminToken');
  const headers = token ? {Authorization:`Bearer ${token}`,'X-Admin-Token':token} : {};
  if (body && !(body instanceof FormData)) {
    headers['Content-Type']='application/json';
    const binary = Array.from(new TextEncoder().encode(JSON.stringify(body)),byte=>String.fromCharCode(byte)).join('');
    body=JSON.stringify({payload:btoa(binary)});
  }
  const [name,query='']=action.split('?');
  const response = await fetch(`/api/router.php?action=${encodeURIComponent(name)}${query?'&'+query:''}`, {method,body,headers,credentials:'include'});
  const data = await response.json().catch(()=>({error:'El servidor no devolvió una respuesta válida.'}));
  if(!response.ok) { const error=new Error(data.error || 'No se pudo completar la operación.'); error.status=response.status; throw error; }
  if(data.token) localStorage.setItem('smartisp.adminToken',data.token);
  return data;
}
function notice(message, type='success', target=$('#status')) { target.textContent=message; target.className=`notice ${type}`; target.hidden=false; }
function visual(url, icon='package') { return safeImage(url) ? `<img src="${escapeHtml(url)}" alt="" loading="lazy">` : `<i data-lucide="${escapeHtml(/^[a-z0-9-]+$/.test(icon)?icon:'package')}"></i>`; }
const byId = id => categories.find(item => item.id === id);
const childrenOf = id => categories.filter(item => item.parentCategoryId === id).sort((a,b)=>a.name.localeCompare(b.name,'es'));
const isTop = cat => !cat.parentCategoryId || !byId(cat.parentCategoryId);
const familyName = id => families.find(group => group.id === id)?.name || 'Sin familia';
const familyOf = cat => families.some(group => group.id === cat.macroGroupId) ? cat.macroGroupId : '';
const countOf = cat => Number(cat.totalCount ?? cat.productCount ?? 0);
const plural = (n, one, many) => `${n} ${n===1?one:many}`;
function categoryRow(cat, child=false) {
  const kids = child ? [] : childrenOf(cat.id);
  const own = Number(cat.productCount||0), total = countOf(cat);
  const meta = [`<span>${plural(total,'producto','productos')}${kids.length&&total!==own?` (${own} propios)`:''}</span>`, kids.length?`<span>${plural(kids.length,'subcategoría','subcategorías')}</span>`:'', cat.iconImageUrl?'<span>Imagen propia</span>':'', cat.bannerImageUrl?'<span>Con banner</span>':''].join('');
  const pick = child ? '' : `<label class="row-select" title="Seleccionar para cambiar su familia"><input type="checkbox" data-select="${escapeHtml(cat.id)}" ${selected.has(cat.id)?'checked':''} aria-label="Seleccionar ${escapeHtml(cat.name)}"></label>`;
  return `<article class="category-row${child?' is-child':''}" data-category-id="${escapeHtml(cat.id)}">${pick}<div class="category-visual">${visual(cat.iconImageUrl,cat.icon)}</div><div class="category-info"><h3>${escapeHtml(cat.name)}</h3>${child?`<p>Subcategoría de ${escapeHtml(byId(cat.parentCategoryId)?.name||'')}</p>`:''}<p class="category-meta">${meta}</p></div><div class="category-actions"><button class="button secondary" data-edit="${escapeHtml(cat.id)}">Editar</button><a class="button secondary" href="/editor-catalogo.html?category=${encodeURIComponent(cat.name)}">Productos ↗</a><details><summary class="button secondary" aria-label="Más opciones para ${escapeHtml(cat.name)}">⋯</summary><div class="more-actions"><button data-move="${escapeHtml(cat.id)}">Mover sus productos</button><button class="danger" data-delete="${escapeHtml(cat.id)}">Eliminar categoría</button></div></details></div></article>`;
}
function render() {
  $('#categoryCount').textContent=categories.length; $('#familyCount').textContent=families.length;
  const q=norm($('#search').value), family=$('#familyFilter').value;
  const matches=cat=>!q||norm(cat.name).includes(q);
  const groups=[...families.map(group=>({id:group.id,name:group.name})),{id:'',name:'Sin familia'}];
  let shown=0;
  const html=groups.filter(group=>family==='all'||(family==='ungrouped'?group.id==='':group.id===family)).map(group=>{
    const tops=categories.filter(cat=>isTop(cat)&&familyOf(cat)===group.id).sort((a,b)=>a.name.localeCompare(b.name,'es'));
    const rows=tops.map(cat=>{const kids=childrenOf(cat.id),keep=matches(cat)?kids:kids.filter(matches);if(!matches(cat)&&!keep.length)return '';shown+=1+keep.length;return `<div class="tree-branch">${categoryRow(cat)}${keep.length?`<div class="tree-children">${keep.map(kid=>categoryRow(kid,true)).join('')}</div>`:''}</div>`;}).join('');
    if(!rows)return '';
    const products=tops.reduce((sum,cat)=>sum+countOf(cat),0);
    return `<section class="tree-family"><header><h3>${escapeHtml(group.name)}</h3><span>${plural(tops.length,'categoría','categorías')} · ${plural(products,'producto','productos')}</span></header>${rows}</section>`;
  }).join('');
  $('#resultCount').textContent=`${shown} de ${categories.length} categorías`;
  $('#categoryList').innerHTML=html || '<p class="empty">No hay categorías que coincidan. Prueba otra búsqueda o crea una categoría.</p>';
  $('#familyList').innerHTML=families.map(group=>`<article class="category-row"><div class="category-visual">${visual(group.imageUrl,group.icon)}</div><div class="category-info"><h3>${escapeHtml(group.name)}</h3><p>${categories.filter(cat=>cat.macroGroupId===group.id&&isTop(cat)).length} categorías · ${group.visible?'Visible en tienda':'Oculta en tienda'} · Orden ${Number(group.sortOrder||0)}</p></div><div class="category-actions"><button class="button secondary" data-edit-family="${escapeHtml(group.id)}">Editar familia</button></div></article>`).join('') || '<p class="empty">Crea una familia para organizar tus categorías.</p>';
  renderBulk(); renderTagNotice();
  window.lucide?.createIcons();
}
function renderBulk() {
  [...selected].forEach(id=>{if(!byId(id))selected.delete(id);});
  $('#bulkBar').hidden=!selected.size; $('#bulkCount').textContent=`${plural(selected.size,'categoría seleccionada','categorías seleccionadas')}`;
}
function renderTagNotice() {
  const box=$('#tagNotice'), skipped=tagConversion.skipped||[];
  box.hidden=!tagConversion.pending&&!skipped.length;
  box.innerHTML=(tagConversion.pending?`<p>Hay ${plural(tagConversion.pending,'subcategoría escrita a mano','subcategorías escritas a mano')} (en productos o en etiquetas antiguas) que todavía no son subcategorías de verdad. Conviértelas para verlas y ordenarlas aquí.</p><details><summary>Ver qué va a cambiar</summary><ul>${(tagConversion.created||[]).map(item=>`<li>Nueva subcategoría: ${escapeHtml(item)}</li>`).join('')}${(tagConversion.linked||[]).map(item=>`<li>Pasa a ser subcategoría: ${escapeHtml(item)}</li>`).join('')}</ul></details><button id="convertTags" class="button primary" type="button">Convertir etiquetas en subcategorías</button>`:'')
    +(skipped.length?`<details><summary>${plural(skipped.length,'etiqueta no se pudo convertir','etiquetas no se pudieron convertir')}</summary><ul>${skipped.map(item=>`<li><strong>${escapeHtml(item.tag)}</strong> en ${escapeHtml(item.category)}: ${escapeHtml(item.reason)}</li>`).join('')}</ul></details>`:'');
}
async function load() {
  const data=await api('categories'); categories=data.categories||[]; families=data.macroGroups||[]; tagConversion=data.tagConversion||{pending:0,skipped:[]};
  const value=$('#familyFilter').value;
  const familyOptions=families.map(group=>`<option value="${escapeHtml(group.id)}">${escapeHtml(group.name)}</option>`).join('');
  $('#familyFilter').innerHTML='<option value="all">Todas las familias</option>'+familyOptions+'<option value="ungrouped">Sin familia</option>';
  $('#familyFilter').value=[...$('#familyFilter').options].some(option=>option.value===value)?value:'all';
  $('#bulkFamily').innerHTML=familyOptions+'<option value="">Sin familia</option>';
  render();
}
function preview(form,name) {
  const element=form.querySelector(`[data-preview="${name}"]`), url=form.elements[name]?.value.trim(); if(!element)return;
  element.replaceChildren(); if(!safeImage(url)){element.textContent='Sin imagen';return;}
  const img=new Image(); img.src=url; img.alt=''; img.onerror=()=>{element.textContent='No se pudo cargar la imagen';}; element.append(img);
}
// Foto del formulario al abrir el diálogo: cerrar con cambios pide confirmación.
const formSnapshot=form=>JSON.stringify([...form.elements].filter(el=>el.name&&el.type!=='file').map(el=>[el.name,el.type==='checkbox'?el.checked:el.value]));
const openDialog=dialog=>{dialog.showModal();const form=dialog.querySelector('form');if(form)form.dataset.snapshot=formSnapshot(form);};
const canDiscard=dialog=>{const form=dialog.querySelector('form');return !form||!form.dataset.snapshot||formSnapshot(form)===form.dataset.snapshot||confirm('Hay cambios sin guardar. ¿Cerrar y descartarlos?');};
const extraNames=new Map();
const nameOf=id=>byId(id)?.name||extraNames.get(id)||id;
const stagedChildren=()=>$('#categoryForm').elements.childIds.value.split(',').filter(Boolean);
function renderTreeFields() {
  const form=$('#categoryForm'), parentId=form.elements.parentCategoryId.value, kids=stagedChildren(), parent=byId(parentId);
  $('#parentCurrent').innerHTML=parentId?`<span class="chip is-active">${escapeHtml(nameOf(parentId))}</span><button type="button" class="link-button" data-clear-parent>Quitar (será categoría principal)</button>`:'<span class="muted">Es una categoría principal.</span>';
  const blocked=Boolean(editingId&&kids.length);
  $('#parentPicker').hidden=blocked||Boolean(parentId);
  $('#parentHint').textContent=blocked?'Tiene subcategorías, así que no puede ser subcategoría de otra.':(parentId?'Usa la familia de su categoría principal.':'Déjalo vacío si es una categoría principal.');
  form.elements.macroGroupId.disabled=Boolean(parentId);
  if(parent)form.elements.macroGroupId.value=parent.macroGroupId||'';
  $('#familyHint').textContent=parentId?'Las subcategorías van en la familia de su categoría principal.':'La familia agrupa categorías en el menú de la tienda; no cambia sus productos. Sus subcategorías la siguen.';
  $('#childrenSection').hidden=Boolean(parentId);
  $('#childList').innerHTML=kids.map(id=>`<li><span>${escapeHtml(nameOf(id))}</span>${byId(id)?`<small>${plural(countOf(byId(id)),'producto','productos')}</small>`:''}<button type="button" class="button secondary" data-remove-child="${escapeHtml(id)}">Quitar</button></li>`).join('')||'<li class="muted">Todavía no tiene subcategorías.</li>';
}
const setTreeValue=(name,value)=>{$('#categoryForm').elements[name].value=value;renderTreeFields();};
let searchTimer=0, searchSeq=0;
function searchCategories(input, results, mode) {
  clearTimeout(searchTimer);
  const q=input.value.trim();
  if(!q){results.innerHTML='';return;}
  searchTimer=setTimeout(async()=>{
    const seq=++searchSeq;
    try {
      const data=await api(`admin-categories-search?q=${encodeURIComponent(q)}&for=${encodeURIComponent(editingId)}&mode=${mode}`);
      if(seq!==searchSeq)return;
      const staged=stagedChildren();
      const list=(data.categories||[]).filter(item=>mode!=='child'||!staged.includes(item.id));
      list.forEach(item=>extraNames.set(item.id,item.name));
      results.innerHTML=list.map(item=>`<button type="button" role="option" data-pick-${mode}="${escapeHtml(item.id)}"><span>${escapeHtml(item.name)}</span><small>${mode==='child'&&item.parentName?`Ahora en ${escapeHtml(item.parentName)}: se moverá aquí`:escapeHtml(familyName(item.macroGroupId))}</small></button>`).join('')||`<p class="muted">${mode==='child'?'No hay categorías que se puedan agregar con ese nombre.':'No hay categorías principales con ese nombre.'}</p>`;
    } catch(error){results.innerHTML=`<p class="muted">${escapeHtml(error.message)}</p>`;}
  },250);
}
function editCategory(id='') {
  editingId=id; const cat=categories.find(item=>item.id===id)||{}, form=$('#categoryForm'); form.reset();
  form.querySelectorAll('details').forEach(details=>details.open=false);
  form.elements.macroGroupId.innerHTML='<option value="">Sin familia</option>'+families.map(group=>`<option value="${escapeHtml(group.id)}">${escapeHtml(group.name)}</option>`).join('');
  for(const field of ['id','name','description','macroGroupId','parentCategoryId','iconImageUrl','bannerImageUrl','bannerAlt'])form.elements[field].value=cat[field]||'';
  originalChildIds=id?childrenOf(id).map(item=>item.id):[]; form.elements.childIds.value=originalChildIds.join(',');
  ['#parentSearch','#childSearch'].forEach(sel=>{$(sel).value='';}); $('#parentResults').innerHTML=''; $('#childResults').innerHTML='';
  form.elements.icon.value=cat.icon||'package';
  if(!form.elements.icon.value){const option=new Option(cat.icon,cat.icon);form.elements.icon.add(option);form.elements.icon.value=cat.icon;}
  form.elements.keywords.value=(cat.keywords||[]).join('\n'); form.elements.id.readOnly=Boolean(id);
  $('#categoryTitle').textContent=id?`Editar ${cat.name}`:'Nueva categoría'; $('#categoryError').hidden=true; $('#uploadStatus').textContent='';
  renderTreeFields();
  ['iconImageUrl','bannerImageUrl'].forEach(field=>preview(form,field)); openDialog($('#categoryDialog'));
}
function editFamily(id='') {
  const group=families.find(item=>item.id===id)||{}, form=$('#familyForm'); form.reset();
  for(const field of ['id','name','icon','imageUrl'])form.elements[field].value=group[field]|| (field==='icon'?'package':'');
  form.elements.sortOrder.value=group.sortOrder||0;form.elements.visible.checked=group.visible!==false;form.elements.keywords.value=(group.keywords||[]).join('\n');
  $('#familyTitle').textContent=id?`Editar ${group.name}`:'Nueva familia';$('#familyError').hidden=true;$('#familyUploadStatus').textContent='';preview(form,'imageUrl');openDialog($('#familyDialog'));
}
$('#categoryForm [name="name"]').addEventListener('input',event=>{if(!editingId)$('#categoryForm [name="id"]').value=slug(event.target.value);});
$('#parentSearch').addEventListener('input',event=>searchCategories(event.target,$('#parentResults'),'parent'));
$('#childSearch').addEventListener('input',event=>searchCategories(event.target,$('#childResults'),'child'));
for(const form of [$('#categoryForm'),$('#familyForm')]) {
  form.addEventListener('input',event=>{if(['iconImageUrl','bannerImageUrl','imageUrl'].includes(event.target.name))preview(form,event.target.name);});
  form.addEventListener('change',async event=>{
    const field=event.target.dataset.upload,file=event.target.files?.[0];if(!field||!file)return;
    const status=form.querySelector('[id$="UploadStatus"],#uploadStatus');
    if(file.size>8*1024*1024){status.textContent='La imagen supera el límite de 8 MB.';return;}
    pendingUploads++;$('#saveCategory').disabled=true;$('#saveFamily').disabled=true; status.textContent='Subiendo imagen…';
    try {const body=new FormData();body.append('image',file);const result=await api('upload-merch-image?category=categories',{method:'POST',body});form.elements[field].value=result.url;preview(form,field);status.textContent='Imagen lista. Guarda para aplicar el cambio.';}
    catch(error){status.textContent=error.message;}
    finally{pendingUploads--;$('#saveCategory').disabled=pendingUploads>0;$('#saveFamily').disabled=pendingUploads>0;event.target.value='';}
  });
}
async function submit(form,errorTarget,action,body,success,after) {
  const button=form.querySelector('[type="submit"]');button.disabled=true;errorTarget.hidden=true;
  try {await api(action,{method:'POST',body});if(after)await after();form.closest('dialog').close();notice(success);await load();}
  catch(error){notice(error.message,'error',form.closest('dialog').open?errorTarget:$('#status'));}
  finally{button.disabled=false;}
}
$('#categoryForm').addEventListener('submit',async event=>{
  event.preventDefault();if(pendingUploads)return;const form=event.target;
  const category=Object.fromEntries(new FormData(form));delete category.childIds;category.keywords=splitLines(category.keywords);
  if(form.elements.macroGroupId.disabled)delete category.macroGroupId;
  const kids=category.parentCategoryId?[]:stagedChildren(), add=kids.filter(id=>!originalChildIds.includes(id)), remove=originalChildIds.filter(id=>!kids.includes(id));
  // Si las subcategorías fallan, la categoría ya quedó guardada: el diálogo sigue abierto con lo pendiente.
  const after=add.length||remove.length?async()=>{await api('admin-category-children',{method:'POST',body:{parentId:category.id,add,remove}});originalChildIds=kids;}:null;
  await submit(form,$('#categoryError'),'categories',{category},'Categoría guardada.',after);
});
$('#familyForm').addEventListener('submit',async event=>{
  event.preventDefault();if(pendingUploads)return;const form=event.target,body=Object.fromEntries(new FormData(form));body.visible=form.elements.visible.checked;body.sortOrder=Number(body.sortOrder);body.keywords=splitLines(body.keywords);
  await submit(form,$('#familyError'),'macro-groups',body,'Familia guardada.');
});
$('#moveForm').addEventListener('submit',async event=>{event.preventDefault();await submit(event.target,$('#moveError'),'categories-reassign',Object.fromEntries(new FormData(event.target)),'Productos movidos.');});
document.addEventListener('change',event=>{const id=event.target.dataset?.select;if(!id)return;if(event.target.checked)selected.add(id);else selected.delete(id);renderBulk();});
document.addEventListener('click',async event=>{
  const button=event.target.closest('button,[data-clear]');if(!button)return;
  if(button.hasAttribute('data-close')) {const dialog=button.closest('dialog');if(!pendingUploads&&canDiscard(dialog))dialog.close();return;}
  if(button.dataset.clear){const form=button.closest('form');form.elements[button.dataset.clear].value='';preview(form,button.dataset.clear);return;}
  if(button.dataset.pickParent){setTreeValue('parentCategoryId',button.dataset.pickParent);$('#parentSearch').value='';$('#parentResults').innerHTML='';return;}
  if(button.hasAttribute('data-clear-parent')){setTreeValue('parentCategoryId','');return;}
  if(button.dataset.pickChild){setTreeValue('childIds',[...stagedChildren(),button.dataset.pickChild].join(','));button.remove();$('#childSearch').focus();return;}
  if(button.dataset.removeChild){setTreeValue('childIds',stagedChildren().filter(id=>id!==button.dataset.removeChild).join(','));return;}
  if(button.dataset.edit){editCategory(button.dataset.edit);return;}
  if(button.dataset.editFamily){editFamily(button.dataset.editFamily);return;}
  if(button.dataset.view){document.querySelectorAll('[data-view]').forEach(tab=>{const active=tab===button;tab.classList.toggle('selected',active);tab.setAttribute('aria-pressed',String(active));});$('#categoriesView').hidden=button.dataset.view!=='categories';$('#familiesView').hidden=button.dataset.view!=='families';return;}
  if(button.id==='bulkClear'){selected.clear();render();return;}
  if(button.id==='bulkApply'){
    button.disabled=true;
    try {const result=await api('admin-category-family',{method:'POST',body:{ids:[...selected],macroGroupId:$('#bulkFamily').value}});selected.clear();await load();notice(`Familia cambiada en ${plural(result.updated,'categoría','categorías')} y sus subcategorías.${result.skipped?.length?` No se cambiaron ${result.skipped.join(', ')}: son subcategorías y siguen a su categoría principal.`:''}`);}
    catch(error){notice(error.message,'error');}
    finally{button.disabled=false;}
    return;
  }
  if(button.id==='convertTags'){
    if(!confirm('Las etiquetas escritas a mano pasarán a ser subcategorías reales. Los productos no cambian de categoría. ¿Continuar?'))return;
    button.disabled=true;
    try {const result=await api('admin-category-convert-tags',{method:'POST',body:{}});await load();notice(`Listo: ${plural(result.created.length,'subcategoría nueva','subcategorías nuevas')} y ${plural(result.linked.length,'categoría existente enlazada','categorías existentes enlazadas')}.${result.skipped.length?` ${plural(result.skipped.length,'etiqueta quedó','etiquetas quedaron')} sin convertir (ver detalle).`:''}`);}
    catch(error){notice(error.message,'error');button.disabled=false;}
    return;
  }
  if(button.dataset.move){
    const cat=categories.find(item=>item.id===button.dataset.move),form=$('#moveForm');form.elements.fromCategory.value=cat.name;
    form.elements.toCategory.innerHTML=categories.filter(item=>item.id!==cat.id).map(item=>`<option value="${escapeHtml(item.name)}">${escapeHtml(item.name)}</option>`).join('');
    $('#moveDescription').textContent=`Mover todos los productos de ${cat.name} a otra categoría.`;$('#moveError').hidden=true;openDialog($('#moveDialog'));return;
  }
  if(button.dataset.delete){const cat=categories.find(item=>item.id===button.dataset.delete);if(!confirm(`¿Eliminar ${cat.name}? Solo se puede eliminar si no tiene productos.${childrenOf(cat.id).length?' Sus subcategorías pasarán a ser categorías principales.':''}`))return;try{await api(`categories?id=${encodeURIComponent(cat.id)}`,{method:'DELETE'});await load();notice('Categoría eliminada.');}catch(error){notice(error.message,'error');}}
});
$('#newCategory').addEventListener('click',()=>editCategory());$('#newFamily').addEventListener('click',()=>editFamily());
$('#search').addEventListener('input',render);$('#familyFilter').addEventListener('change',render);$('#reload').addEventListener('click',()=>load().catch(error=>notice(error.message,'error')));
for(const dialog of document.querySelectorAll('dialog'))dialog.addEventListener('cancel',event=>{if(pendingUploads||!canDiscard(dialog))event.preventDefault();});
(async()=>{
  try {const {user}=await api('me');if(!['admin','administrator','administrador'].includes(String(user?.role).trim().toLowerCase()))throw new Error('Tu cuenta no tiene acceso a la administración.');await load();$('#app').hidden=false;$('#status').hidden=true;}
  catch(error){if(error.status===401)location.assign(`/login.html?admin=1&redirect=${encodeURIComponent('/categorias.html')}`);else notice(error.message,'error');}
})();
})();
