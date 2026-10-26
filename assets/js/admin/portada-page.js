document.addEventListener('DOMContentLoaded', () => SmartAdmin.boot(async user => {
    const { $, api, post, icons } = SmartAdmin;
    const escapeHtml = SmartAdmin.esc;
    const state = { featuredPage:{eyebrow:'Selección comercial',title:'Productos destacados para redes, empresas y tecnología',description:'Una vitrina rápida de artículos del catálogo SmartISP: conectividad, cómputo, energía, seguridad, periféricos y equipamiento TI.',note:'Selección pensada para partir rápido: revisa precio, categoría y ficha antes de cotizar o comprar.',products_title:'Selección destacada',products_description:'Productos elegidos para mostrar novedades y alta rotación.'}, products:[], categoryIds:[] };
    let productResults = [];
    let availableCategories = [];
    let macroGroups = [];
    let brands = [];
    let loaded = false;
    let savedSnapshot = null;
    let searchSeq = 0;
    // Familias y marcas se guardan al instante: sus POST van en fila para que no se pisen entre sí.
    let saveChain = Promise.resolve();
    const enqueue = task => (saveChain = saveChain.catch(() => {}).then(task));
    function message(text,error=false) { const target=$('#saveMessage'); target.textContent=text; target.classList.toggle('error',error); target.classList.add('show'); }
    const imageOf = product => product?.imageUrl || product?.image_url || '/assets/favicons/favicon-96x96.png';
    const formCopy = () => ({eyebrow:$('#featuredEyebrow').value.trim(),title:$('#featuredTitle').value.trim(),description:$('#featuredDescription').value.trim(),note:$('#featuredNote').value.trim(),products_title:$('#featuredProductsTitle').value.trim(),products_description:$('#featuredProductsDescription').value.trim()});
    const snapshot = () => JSON.stringify({copy:formCopy(),products:state.products.map(product=>String(product.id)),categories:state.categoryIds});
    const isDirty = () => loaded && savedSnapshot !== null && snapshot() !== savedSnapshot;
    const syncDirty = () => { $('#dirtyNote').hidden = !isDirty(); };
    $('#identity').textContent=`${user.name || ''} ${user.surname || ''} · ${user.email || ''}`;
    function renderFeaturedCopyPreview() {
      const values={eyebrow:$('#featuredEyebrow').value,title:$('#featuredTitle').value,description:$('#featuredDescription').value,note:$('#featuredNote').value,products_title:$('#featuredProductsTitle').value,products_description:$('#featuredProductsDescription').value};
      $('#featuredCopyPreview').innerHTML=`<strong>${escapeHtml(values.eyebrow||'Etiqueta')}</strong><h3 style="margin:8px 0;color:var(--ink)">${escapeHtml(values.title||'Título de Selección comercial')}</h3><p style="margin:0 0 8px">${escapeHtml(values.description||'Descripción de la página')}</p><p style="margin:0 0 16px">${escapeHtml(values.note||'Texto auxiliar')}</p><strong>${escapeHtml(values.products_title||'Título de productos')}</strong><br>${escapeHtml(values.products_description||'Descripción de los productos')}`;
    }
    function renderCategoryChoices() {
      const selectedHost=$('#categorySelectedList'), availableHost=$('#categoryAvailableList');
      const byId=id=>availableCategories.find(category=>String(category.id)===String(id));
      $('#categorySelectedCount').textContent=state.categoryIds.length;
      selectedHost.innerHTML=state.categoryIds.map((id,index)=>{
        const category=byId(id);
        if(!category)return '';
        return `<div class="category-selected-row" data-category-id="${escapeHtml(id)}"><span class="drag-index">${index+1}</span><img src="${escapeHtml(category.iconImageUrl||'/assets/favicons/favicon-96x96.png')}" alt="" loading="lazy"><div><strong>${escapeHtml(category.name)}</strong><small>${Number(category.productCount||0)} productos</small></div><div class="icon-actions"><button class="icon-button" type="button" data-move-category="-1" aria-label="Mover arriba" ${index===0?'disabled':''}><i data-lucide="arrow-up"></i></button><button class="icon-button" type="button" data-move-category="1" aria-label="Mover abajo" ${index===state.categoryIds.length-1?'disabled':''}><i data-lucide="arrow-down"></i></button><button class="icon-button" type="button" data-remove-category aria-label="Quitar categoría"><i data-lucide="x"></i></button></div></div>`;
      }).join('')||'<p class="category-selected-empty">Ninguna elegida todavía: la portada muestra automáticamente las familias con más productos. Toca una de abajo para destacarla aquí.</p>';
      syncDirty();
      if(!availableCategories.length){availableHost.innerHTML='<p class="muted">No se pudieron cargar categorías disponibles.</p>';icons();return;}
      const query=($('#categoryAvailableSearch').value||'').trim().toLocaleLowerCase();
      const atLimit=state.categoryIds.length>=7;
      availableHost.innerHTML=availableCategories.filter(category=>Number(category.productCount||0)>0 && !state.categoryIds.includes(String(category.id)) && (!query||category.name.toLocaleLowerCase().includes(query))).map(category=>`<button class="category-choice" type="button" data-add-category="${escapeHtml(category.id)}" ${atLimit?'disabled':''}><img src="${escapeHtml(category.iconImageUrl||'/assets/favicons/favicon-96x96.png')}" alt="" loading="lazy"><span>${escapeHtml(category.name)} · ${Number(category.productCount||0)}</span></button>`).join('')||'<p class="muted">No hay más categorías disponibles.</p>';
      icons();
    }
    function renderMacroGroups() {
      const host=$('#macroGroupList');
      if(!macroGroups.length){host.innerHTML='<p class="muted">No hay familias configuradas.</p>';return;}
      host.innerHTML=macroGroups.map((group,index)=>`<div class="macro-group-row" data-macro="${escapeHtml(group.id)}">
        ${group.imageUrl?`<img src="${escapeHtml(group.imageUrl)}" alt="" loading="lazy">`:`<span class="thumb-empty"><i data-lucide="${escapeHtml(group.icon||'package')}" width="20"></i></span>`}
        <div><strong>${escapeHtml(group.name)}</strong><small>${group.visible?'Visible en portada':'Oculta'}</small></div>
        <div class="macro-group-actions">
          <label class="button secondary" style="margin:0"><i data-lucide="image-up"></i><input type="file" accept="image/jpeg,image/png,image/webp" hidden data-macro-upload></label>
          <button class="icon-button" type="button" data-macro-toggle aria-label="${group.visible?'Ocultar':'Mostrar'}"><i data-lucide="${group.visible?'eye':'eye-off'}"></i></button>
          <button class="icon-button" type="button" data-macro-move="-1" ${index===0?'disabled':''} aria-label="Subir"><i data-lucide="arrow-up"></i></button>
          <button class="icon-button" type="button" data-macro-move="1" ${index===macroGroups.length-1?'disabled':''} aria-label="Bajar"><i data-lucide="arrow-down"></i></button>
        </div>
      </div>`).join('');
      icons();
    }
    async function saveMacroGroup(group) {
      await post('macro-groups',{id:group.id,name:group.name,icon:group.icon,sortOrder:group.sortOrder,imageUrl:group.imageUrl,visible:group.visible});
    }
    // Solo se guardan las filas cuyo orden cambió, una tras otra.
    async function persistMacroGroupOrder() {
      try {
        for (const [index,group] of macroGroups.entries()) {
          if (group.savedOrder===index) continue;
          group.sortOrder=index; await saveMacroGroup(group); group.savedOrder=index;
        }
      } catch(error) { message(error.message,true); }
    }
    let macroOrderTimer, brandOrderTimer;
    const scheduleMacroOrder = () => { clearTimeout(macroOrderTimer); macroOrderTimer=setTimeout(()=>enqueue(persistMacroGroupOrder),600); };
    const scheduleBrandOrder = () => { clearTimeout(brandOrderTimer); brandOrderTimer=setTimeout(()=>enqueue(persistBrandOrder),600); };
    function renderBrands() {
      const host=$('#brandList');
      if(!brands.length){host.innerHTML='<p class="muted">Aún no hay marcas agregadas.</p>';return;}
      host.innerHTML=brands.map((brand,index)=>`<div class="brand-row" data-brand="${escapeHtml(brand.id)}">
        <img src="${escapeHtml(brand.logoUrl)}" alt="${escapeHtml(brand.name)}" loading="lazy">
        <div><input type="text" value="${escapeHtml(brand.name)}" maxlength="150" data-brand-name aria-label="Nombre de la marca"></div>
        <div class="brand-actions">
          <label class="check" style="display:flex;align-items:center;gap:5px;margin:0;font-size:12px;font-weight:400"><input type="checkbox" ${brand.isActive?'checked':''} data-brand-active style="width:auto;margin:0"> Activa</label>
          <label class="icon-button" aria-label="Reemplazar logo"><i data-lucide="image-up"></i><input type="file" accept="image/jpeg,image/png,image/webp" hidden data-brand-logo-upload></label>
          <button class="icon-button" type="button" data-brand-move="-1" ${index===0?'disabled':''} aria-label="Subir"><i data-lucide="arrow-up"></i></button>
          <button class="icon-button" type="button" data-brand-move="1" ${index===brands.length-1?'disabled':''} aria-label="Bajar"><i data-lucide="arrow-down"></i></button>
          <button class="icon-button" type="button" data-brand-remove aria-label="Quitar marca"><i data-lucide="x"></i></button>
        </div>
      </div>`).join('');
      icons();
    }
    async function saveBrand(brand) {
      const result=await post('brands',{id:brand.id>0?brand.id:undefined,name:brand.name,logoUrl:brand.logoUrl,sortOrder:brand.sortOrder,isActive:brand.isActive});
      if(result.id) brand.id=result.id;
    }
    async function persistBrandOrder() {
      try {
        for (const [index,brand] of brands.entries()) {
          if (brand.savedOrder===index) continue;
          brand.sortOrder=index; await saveBrand(brand); brand.savedOrder=index;
        }
      } catch(error) { message(error.message,true); }
    }
    function renderProducts() {
      $('#selected').innerHTML=state.products.map((product,index)=>`<div class="product-row" data-product="${index}"><img src="${escapeHtml(imageOf(product))}" alt="" width="48" height="48" loading="lazy"><div><strong>${escapeHtml(product.name)}</strong><small>${escapeHtml(product.category||'')}${product.sku?` · SKU ${escapeHtml(product.sku)}`:''}</small></div><div class="icon-actions"><button class="icon-button" data-move-product="-1" type="button" aria-label="Mover arriba" ${index===0?'disabled':''}><i data-lucide="arrow-up"></i></button><button class="icon-button" data-move-product="1" type="button" aria-label="Mover abajo" ${index===state.products.length-1?'disabled':''}><i data-lucide="arrow-down"></i></button><button class="icon-button" data-remove-product type="button" aria-label="Quitar producto"><i data-lucide="x"></i></button></div></div>`).join('') || '<p class="muted">Aún no hay productos destacados.</p>';
      $('#results').innerHTML=productResults.map((product,index)=>{const exists=state.products.some(item=>String(item.id)===String(product.id));return `<div class="product-row"><img src="${escapeHtml(imageOf(product))}" alt="" width="48" height="48" loading="lazy"><div><strong>${escapeHtml(product.name)}</strong><small>${escapeHtml(product.category||'')}${product.sku?` · SKU ${escapeHtml(product.sku)}`:''}</small></div><button class="button secondary" type="button" data-add-product="${index}" ${exists||state.products.length>=12?'disabled':''}>${exists?'Agregado':'Destacar'}</button></div>`}).join(''); icons();
      syncDirty();
    }
    function fillForm() {
      $('#featuredEyebrow').value=state.featuredPage.eyebrow; $('#featuredTitle').value=state.featuredPage.title; $('#featuredDescription').value=state.featuredPage.description; $('#featuredNote').value=state.featuredPage.note;
      $('#featuredProductsTitle').value=state.featuredPage.products_title; $('#featuredProductsDescription').value=state.featuredPage.products_description;
      renderProducts(); renderFeaturedCopyPreview();
    }
    async function publish() {
      if(!loaded){message('La portada no se cargó: recarga la página antes de publicar para no sobrescribirla.',true);return;}
      const button=$('#save'); button.disabled=true;
      try {
        state.featuredPage=formCopy();
        const sent=snapshot();
        // Solo manda lo que esta pantalla administra -- sin 'campaign', 'slides' ni 'chips', para
        // no tocar nada de lo que gestiona Banners (ver guarda por clave en campaigns.php).
        const payload={zone:'store-home',featured_page:state.featuredPage,product_ids:state.products.map(product=>product.id),category_ids:state.categoryIds};
        await post('admin-store-campaigns?zone=store-home',payload);
        savedSnapshot=sent; syncDirty();
        message('Publicado. La portada ya está actualizada.');
      } catch(error) { message(error.message,true); }
      finally { button.disabled=false; }
    }
    async function load() {
      try {
        const result=await api('admin-store-campaigns?zone=store-home');
        Object.assign(state.featuredPage,result.featured_page||{});
        state.products=result.products||[];
        state.categoryIds=(result.featured_category_ids||[]).map(String);
        const categoriesResult=await api('categories');
        availableCategories=categoriesResult.categories||[];
        macroGroups=categoriesResult.macroGroups||[];
        macroGroups.forEach((group,index)=>{group.savedOrder=index;});
        brands=(await api('brands?admin=1')).brands||[];
        brands.forEach((brand,index)=>{brand.savedOrder=index;});
        fillForm();
        renderCategoryChoices();
        renderMacroGroups();
        renderBrands();
        loaded=true;
        savedSnapshot=snapshot(); syncDirty();
      } catch(error) {
        $('#save').disabled=true;
        message(`No se pudo cargar la portada: ${error.message}. Recarga la página antes de publicar.`,true);
      }
    }
    document.querySelectorAll('#featuredEyebrow,#featuredTitle,#featuredDescription,#featuredNote,#featuredProductsTitle,#featuredProductsDescription').forEach(el=>el.addEventListener('input',()=>{renderFeaturedCopyPreview();syncDirty();}));
    $('#categoryAvailableList').addEventListener('click',event=>{
      const btn=event.target.closest('[data-add-category]');if(!btn)return;
      if(state.categoryIds.length>=7){message('Puedes elegir hasta 7 categorías.',true);return;}
      state.categoryIds=[...state.categoryIds,btn.dataset.addCategory];
      renderCategoryChoices();
    });
    $('#categoryAvailableSearch').addEventListener('input',renderCategoryChoices);
    $('#categorySelectedList').addEventListener('click',event=>{
      const row=event.target.closest('[data-category-id]');if(!row)return;
      const id=row.dataset.categoryId, index=state.categoryIds.indexOf(id);if(index===-1)return;
      if(event.target.closest('[data-remove-category]')){state.categoryIds=state.categoryIds.filter(item=>item!==id);renderCategoryChoices();return;}
      const moveBtn=event.target.closest('[data-move-category]');
      if(moveBtn){
        const to=index+Number(moveBtn.dataset.moveCategory);
        if(to<0||to>=state.categoryIds.length)return;
        const next=[...state.categoryIds];[next[index],next[to]]=[next[to],next[index]];
        state.categoryIds=next;renderCategoryChoices();
      }
    });
    $('#searchButton').addEventListener('click',async()=>{
      const q=$('#productSearch').value.trim(), seq=++searchSeq;
      if(!q){productResults=[];renderProducts();return;}
      try{const result=await api(`admin-products?q=${encodeURIComponent(q)}&limit=20&visible=visible`);if(seq!==searchSeq)return;productResults=result.products||[];renderProducts();}
      catch(error){if(seq===searchSeq)message(error.message,true);}
    });
    $('#productSearch').addEventListener('keydown',event=>{if(event.key==='Enter'){event.preventDefault();$('#searchButton').click();}});
    $('#results').addEventListener('click',event=>{
      const btn=event.target.closest('[data-add-product]');if(!btn)return;
      const product=productResults[Number(btn.dataset.addProduct)];
      if(product&&!state.products.some(item=>String(item.id)===String(product.id))&&state.products.length<12){state.products.push(product);renderProducts();}
    });
    $('#selected').addEventListener('click',event=>{
      const removeBtn=event.target.closest('[data-remove-product]');
      const moveBtn=event.target.closest('[data-move-product]');
      const row=event.target.closest('[data-product]');if(!row)return;
      const index=Number(row.dataset.product);
      if(removeBtn){state.products.splice(index,1);renderProducts();return;}
      if(moveBtn){const to=index+Number(moveBtn.dataset.moveProduct);if(to<0||to>=state.products.length)return;[state.products[index],state.products[to]]=[state.products[to],state.products[index]];renderProducts();}
    });
    $('#macroGroupList').addEventListener('click',event=>{
      const row=event.target.closest('[data-macro]');if(!row)return;
      const group=macroGroups.find(g=>g.id===row.dataset.macro);if(!group)return;
      if(event.target.closest('[data-macro-toggle]')){
        group.visible=!group.visible;renderMacroGroups();
        enqueue(async()=>{
          try{await saveMacroGroup(group);}
          catch(error){group.visible=!group.visible;renderMacroGroups();message(`No se pudo cambiar la visibilidad de "${group.name}": ${error.message}`,true);}
        });
        return;
      }
      const moveBtn=event.target.closest('[data-macro-move]');
      if(moveBtn){
        const index=macroGroups.indexOf(group),to=index+Number(moveBtn.dataset.macroMove);
        if(to<0||to>=macroGroups.length)return;
        [macroGroups[index],macroGroups[to]]=[macroGroups[to],macroGroups[index]];
        renderMacroGroups();scheduleMacroOrder();
      }
    });
    $('#macroGroupList').addEventListener('change',async event=>{
      const upload=event.target.closest('[data-macro-upload]');if(!upload||!upload.files?.length)return;
      const row=event.target.closest('[data-macro]'),group=macroGroups.find(g=>g.id===row.dataset.macro);if(!group)return;
      const file=upload.files[0];
      if(file.size>8*1024*1024){message('La imagen supera el límite de 8 MB.',true);return;}
      try{
        const form=new FormData();form.append('image',file);
        const result=await api('upload-merch-image?category=categories',{method:'POST',body:form});
        const previous=group.imageUrl;
        group.imageUrl=result.url;renderMacroGroups();
        await enqueue(async()=>{try{await saveMacroGroup(group);}catch(error){group.imageUrl=previous;renderMacroGroups();throw error;}});
        message('Imagen de familia actualizada.');
      }catch(error){message(error.message,true);}
    });
    $('#brandList').addEventListener('click',async event=>{
      const row=event.target.closest('[data-brand]');if(!row)return;
      const brand=brands.find(b=>String(b.id)===row.dataset.brand);if(!brand)return;
      if(event.target.closest('[data-brand-remove]')){
        if(!confirm(`¿Quitar la marca "${brand.name}"?`))return;
        try{await enqueue(()=>api(`brands?id=${brand.id}`,{method:'DELETE'}));brands=brands.filter(b=>b!==brand);renderBrands();scheduleBrandOrder();}catch(error){message(error.message,true);}
        return;
      }
      const moveBtn=event.target.closest('[data-brand-move]');
      if(moveBtn){
        const index=brands.indexOf(brand),to=index+Number(moveBtn.dataset.brandMove);
        if(to<0||to>=brands.length)return;
        [brands[index],brands[to]]=[brands[to],brands[index]];
        renderBrands();scheduleBrandOrder();
      }
    });
    $('#brandList').addEventListener('change',async event=>{
      const row=event.target.closest('[data-brand]');if(!row)return;
      const brand=brands.find(b=>String(b.id)===row.dataset.brand);if(!brand)return;
      if(event.target.closest('[data-brand-active]')){
        const checkbox=event.target;
        brand.isActive=checkbox.checked;
        enqueue(async()=>{try{await saveBrand(brand);}catch(error){brand.isActive=!checkbox.checked;checkbox.checked=brand.isActive;message(error.message,true);}});
        return;
      }
      const logoUpload=event.target.closest('[data-brand-logo-upload]');
      if(logoUpload&&logoUpload.files?.length){
        const file=logoUpload.files[0];
        if(file.size>8*1024*1024){message('El logo supera el límite de 8 MB.',true);logoUpload.value='';return;}
        const previous=brand.logoUrl;
        try{
          const form=new FormData();form.append('image',file);
          const result=await api('upload-merch-image?category=brands',{method:'POST',body:form});
          brand.logoUrl=result.url;
          await enqueue(()=>saveBrand(brand));
          renderBrands();
          message('Logo actualizado.');
        }catch(error){brand.logoUrl=previous;message(error.message,true);}
        finally{logoUpload.value='';}
      }
    });
    $('#brandList').addEventListener('blur',event=>{
      const nameInput=event.target.closest('[data-brand-name]');if(!nameInput)return;
      const row=event.target.closest('[data-brand]'),brand=brands.find(b=>String(b.id)===row.dataset.brand);if(!brand)return;
      const name=nameInput.value.trim();
      if(!name){nameInput.value=brand.name;return;}
      if(name===brand.name)return;
      const previous=brand.name;
      brand.name=name;
      enqueue(async()=>{try{await saveBrand(brand);}catch(error){brand.name=previous;if(document.contains(nameInput))nameInput.value=previous;message(error.message,true);}});
    },true);
    $('#newBrandLogo').addEventListener('change',()=>{ $('#addBrand').disabled=!$('#newBrandLogo').files?.length || !$('#newBrandName').value.trim(); });
    $('#newBrandName').addEventListener('input',()=>{ $('#addBrand').disabled=!$('#newBrandLogo').files?.length || !$('#newBrandName').value.trim(); });
    $('#addBrand').addEventListener('click',async()=>{
      const name=$('#newBrandName').value.trim(),file=$('#newBrandLogo').files?.[0];
      if(!name||!file)return;
      if(file.size>8*1024*1024){message('El logo supera el límite de 8 MB.',true);return;}
      $('#addBrand').disabled=true;
      try{
        const form=new FormData();form.append('image',file);
        const uploadResult=await api('upload-merch-image?category=brands',{method:'POST',body:form});
        const brand={id:0,name,logoUrl:uploadResult.url,sortOrder:brands.length,isActive:true};
        await enqueue(()=>saveBrand(brand));
        brand.savedOrder=brands.length;
        brands.push(brand);renderBrands();
        $('#newBrandName').value='';$('#newBrandLogo').value='';
        message('Marca agregada.');
      }catch(error){message(error.message,true);}
      finally{$('#addBrand').disabled=true;}
    });
    $('#save').addEventListener('click',publish);
    document.addEventListener('click',event=>{
      const link=event.target.closest('a[href]');
      if(!link||link.target==='_blank'||event.ctrlKey||event.metaKey||!isDirty())return;
      if(!confirm('Hay cambios sin publicar en la portada (textos, categorías o productos destacados). ¿Salir sin publicarlos?')){event.preventDefault();return;}
      savedSnapshot=snapshot();
    });
    window.addEventListener('beforeunload',event=>{if(isDirty()){event.preventDefault();event.returnValue='';}});
    await load();
}));
