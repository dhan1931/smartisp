(function(){
  const pane=document.getElementById('tab-products'),card=pane.querySelector('.content-card'),header=card.querySelector('.card-header');
  const heading=document.createElement('div');heading.className='products-page-heading';heading.innerHTML='<div><h1>Productos</h1><p>Revisa el catálogo, organiza tus productos y completa lo que falta.</p></div>';
  const actions=header.lastElementChild;actions.className='products-primary-actions';heading.append(actions);pane.prepend(heading);
  const create=document.getElementById('btnNewProduct');create.textContent='+ Nuevo producto';
  const importer=[...actions.querySelectorAll('button')].find(button=>button!==create&&button.id!=='btnDeleteAllProducts');if(importer)importer.textContent='Importar Excel';
  header.querySelector('.card-desc').textContent='Busca un producto o combina los filtros para encontrar lo que necesitas.';
  const summary=document.createElement('span');summary.className='product-results';summary.id='productResults';header.append(summary);
  const filters=card.querySelector('.filter-bar');filters.classList.add('products-filters');
  const more=document.createElement('details');more.className='products-extra-filters';more.innerHTML='<summary>Más filtros</summary><div class="products-extra-fields"></div>';
  const fields=more.querySelector('div');
  for(const [id,title] of [['filterSubCat','Subcategoría'],['filterVisibility','Visibilidad'],['filterPrice','Precio'],['filterPhoto','Imagen']]){const input=document.getElementById(id),label=document.createElement('label');label.textContent=title;label.append(input);fields.append(label);}
  filters.append(more);
  const clear=document.createElement('button');clear.type='button';clear.className='btn btn-secondary';clear.textContent='Limpiar filtros';clear.id='clearProductFilters';
  clear.addEventListener('click',()=>{document.getElementById('productSearchInput').value='';['filterFamily','filterMacroCat','filterSubCat','filterVisibility','filterPrice','filterPhoto'].forEach(id=>document.getElementById(id).value='all');renderCategorySelects();loadProductsPage(1).catch(error=>showToast(error.message,'error'));});filters.append(clear);
  for(const [id,title] of [['filterFamily','Familia'],['filterMacroCat','Categoría']]){const input=document.getElementById(id),label=document.createElement('label');label.textContent=title;input.before(label);label.append(input);}
  const stats=pane.querySelectorAll('.stat-label');['Total','Visibles','Con imagen','Sin imagen'].forEach((title,index)=>stats[index].textContent=title);
  const importerPane=document.getElementById('tab-importer'),back=document.createElement('button');back.type='button';back.className='btn btn-secondary product-import-back';back.textContent='← Volver a productos';back.addEventListener('click',()=>switchTab('tab-products'));importerPane.prepend(back);
  card.querySelector('.data-table-wrap').classList.add('products-list-wrap');
  const refresh=document.createElement('button');refresh.type='button';refresh.className='btn btn-secondary';refresh.textContent='Recargar';refresh.addEventListener('click',async()=>{refresh.disabled=true;try{await refreshProducts();}catch(error){showToast(error.message,'error');}finally{refresh.disabled=false;}});header.append(refresh);
})();
