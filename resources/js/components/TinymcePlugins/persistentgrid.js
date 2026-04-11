tinymce.PluginManager.add('persistentgrid', function(editor) {

    const GRID_ATTR = 'data-tmce-grid';
    const ITEM_ATTR = 'data-tmce-grid-item';

    /* -----------------------------
     U til*ities
     ----------------------------- */
    const getWrapper = node => {
        while(node && node !== editor.getBody()){
            if(node.hasAttribute && node.hasAttribute(GRID_ATTR)) return node;
            node = node.parentNode;
        }
        return null;
    };

    const getGrid = wrapper => wrapper ? wrapper.querySelector(':scope > div') : null;

    const createCell = (bg='', borderColor='#ddd', borderWidth='1px', padding='1rem', content='<p>Column</p>') => {
        const cell = editor.dom.create('div', {
            [ITEM_ATTR]: 'true',
            style: `background:${bg}; border:${borderWidth} solid ${borderColor}; padding:${padding};`
        });
        cell.innerHTML = content;
        return cell;
    };

    const applyGridStyles = (grid, minWidth, gap, padding='0') => {
        grid.style.display = 'grid';
        grid.style.gridTemplateColumns = `repeat(auto-fit, minmax(${minWidth}px, 1fr))`;
        grid.style.gap = gap + 'px';
        grid.style.padding = padding;
    };

    const updateGridFromModal = (wrapper, data) => {
        const cols = parseInt(data.columns),
                          minWidth = parseInt(data.minWidth),
                          gap = parseInt(data.gap);

                          const grid = getGrid(wrapper);

                          // Store settings in dataset
                          wrapper.dataset.columns = cols;
                          wrapper.dataset.minwidth = minWidth;
                          wrapper.dataset.gap = gap;
                          wrapper.dataset.containerbg = data.containerBg || '';
                          wrapper.dataset.cellbg = data.cellBg || '';
                          wrapper.dataset.align = data.align || 'left';
                          wrapper.dataset.gridPadding = data.gridPadding || '0';
                          wrapper.dataset.cellPadding = data.cellPadding || '1rem';
                          wrapper.dataset.cellBorderColor = data.cellBorderColor || '#ddd';
                          wrapper.dataset.cellBorderWidth = data.cellBorderWidth || '1px';

                          // Apply styles
                          wrapper.style.background = wrapper.dataset.containerbg;
                          wrapper.style.textAlign = wrapper.dataset.align==='center'?'center':wrapper.dataset.align==='right'?'right':'left';

                          applyGridStyles(grid, minWidth, gap, wrapper.dataset.gridPadding);

                          grid.querySelectorAll(`[${ITEM_ATTR}]`).forEach(cell=>{
                              cell.style.background = wrapper.dataset.cellbg;
                              cell.style.padding = wrapper.dataset.cellPadding;
                              cell.style.borderColor = wrapper.dataset.cellBorderColor;
                              cell.style.borderWidth = wrapper.dataset.cellBorderWidth;
                              cell.style.borderStyle = 'solid';
                              cell.style.textAlign = wrapper.dataset.align==='center'?'center':wrapper.dataset.align==='right'?'right':'left';
                          });

                          editor.undoManager.add();
                          editor.nodeChanged();
    };

    /* -----------------------------
     M oda*l
     ----------------------------- */
    const openDialog = (wrapper=null) => {
        const initial = wrapper ? {
            columns: wrapper.dataset.columns || '3',
            minWidth: wrapper.dataset.minwidth || '250',
            gap: wrapper.dataset.gap || '16',
            containerBg: wrapper.dataset.containerbg || '',
            cellBg: wrapper.dataset.cellbg || '',
            align: wrapper.dataset.align || 'left',
            gridPadding: wrapper.dataset.gridPadding || '0',
            cellPadding: wrapper.dataset.cellPadding || '1rem',
            cellBorderColor: wrapper.dataset.cellBorderColor || '#ddd',
            cellBorderWidth: wrapper.dataset.cellBorderWidth || '1px'
        } : {
            columns:'3', minWidth:'250', gap:'16', containerBg:'', cellBg:'',
            align:'left', gridPadding:'0', cellPadding:'1rem', cellBorderColor:'#ddd', cellBorderWidth:'1px'
        };

        editor.windowManager.open({
            title: wrapper ? 'Edit Grid' : 'Insert Grid',
            body: {
                type:'panel',
                items:[
                    { type:'input', name:'columns', label:'Columns' },
                    { type:'input', name:'minWidth', label:'Min Cell Width(px)' },
                    { type:'input', name:'gap', label:'Gap(px)' },
                    { type:'input', name:'gridPadding', label:'Grid Padding(px)', placeholder:'0' },
                    { type:'input', name:'cellPadding', label:'Cell Padding(px/rem)', placeholder:'1rem' },
                    { type:'input', name:'cellBorderWidth', label:'Cell Border Width(px)', placeholder:'1px' },
                    { type:'colorinput', name:'cellBorderColor', label:'Cell Border Color' },
                    { type:'colorinput', name:'containerBg', label:'Grid Background' },
                    { type:'colorinput', name:'cellBg', label:'Cell Background' },
                    {
                        type:'selectbox', name:'align', label:'Alignment',
                    items:[
                        { text:'Left', value:'left' },
                    { text:'Center', value:'center' },
                    { text:'Right', value:'right' }
                    ]
                    }
                ]
            },
            initialData: initial,
            buttons:[{type:'cancel', text:'Cancel'}, {type:'submit', text:wrapper?'Update':'Insert', primary:true}],
            onSubmit: api=>{
                const data = api.getData();
                if(wrapper){
                    updateGridFromModal(wrapper, data);
                } else {
                    const newWrapper = editor.dom.create('div');
                    newWrapper.setAttribute(GRID_ATTR,'true');
                    newWrapper.dataset.columns = data.columns;
                    newWrapper.dataset.minwidth = data.minWidth;
                    newWrapper.dataset.gap = data.gap;
                    newWrapper.dataset.containerbg = data.containerBg;
                    newWrapper.dataset.cellbg = data.cellBg;
                    newWrapper.dataset.align = data.align;
                    newWrapper.dataset.gridPadding = data.gridPadding;
                    newWrapper.dataset.cellPadding = data.cellPadding;
                    newWrapper.dataset.cellBorderColor = data.cellBorderColor;
                    newWrapper.dataset.cellBorderWidth = data.cellBorderWidth;

                    const grid = editor.dom.create('div');
                    applyGridStyles(grid, parseInt(data.minWidth), parseInt(data.gap), data.gridPadding);

                    for(let i=0;i<parseInt(data.columns);i++){
                        grid.appendChild(createCell(data.cellBg, data.cellBorderColor, data.cellBorderWidth, data.cellPadding));
                    }

                    newWrapper.appendChild(grid);
                    editor.insertContent('<p><br></p>' + newWrapper.outerHTML + '<p><br></p>');
                }
                api.close();
            }
        });
    };

    editor.on('SetContent', () => {
        const body = editor.getBody();
        const first = body.firstChild;
        const last = body.lastChild;
        if(first && first.getAttribute && !first.tagName.match(/P/i)) {
            body.insertBefore(editor.dom.create('p', {}, '<br>'), first);
        }
        if(last && last.getAttribute && !last.tagName.match(/P/i)) {
            body.appendChild(editor.dom.create('p', {}, '<br>'));
        }
    });

    /* -----------------------------
     C ont*ext menu
     ----------------------------- */
    editor.ui.registry.addMenuItem('grid_edit',{text:'Edit Grid', onAction:()=>{ const w=getWrapper(editor.selection.getNode()); if(w) openDialog(w); }});
    editor.ui.registry.addMenuItem('grid_add',{text:'Add Column', onAction:()=>{ const w=getWrapper(editor.selection.getNode()); if(!w) return; const g=getGrid(w); g.appendChild(createCell(w.dataset.cellbg, w.dataset.cellBorderColor, w.dataset.cellBorderWidth, w.dataset.cellPadding)); editor.undoManager.add(); }});
    editor.ui.registry.addMenuItem('grid_remove',{text:'Remove Column', onAction:()=>{ const c=editor.dom.getParent(editor.selection.getNode(), `[${ITEM_ATTR}]`); if(c){editor.dom.remove(c); editor.undoManager.add();} }});
    editor.ui.registry.addContextMenu('persistentgrid',{update:e=>getWrapper(e)?'grid_edit grid_add grid_remove':''});
    editor.ui.registry.addButton('persistentgrid',{icon:'table', tooltip:'Insert Grid', onAction:()=>openDialog()});

    /* -----------------------------
     R est*ore grids on reload
     ----------------------------- */
    const restoreGrids = () => {
        editor.getBody().querySelectorAll(`[${GRID_ATTR}]`).forEach(wrapper=>{
            const grid = getGrid(wrapper);
            applyGridStyles(grid, parseInt(wrapper.dataset.minwidth), parseInt(wrapper.dataset.gap), wrapper.dataset.gridPadding);
            wrapper.style.background = wrapper.dataset.containerbg;
            grid.querySelectorAll(`[${ITEM_ATTR}]`).forEach(cell=>{
                cell.style.background = wrapper.dataset.cellbg;
                cell.style.padding = wrapper.dataset.cellPadding;
                cell.style.borderColor = wrapper.dataset.cellBorderColor;
                cell.style.borderWidth = wrapper.dataset.cellBorderWidth;
                cell.style.borderStyle = 'solid';
                cell.style.textAlign = wrapper.dataset.align==='center'?'center':wrapper.dataset.align==='right'?'right':'left';
            });
        });
    };
    editor.on('SetContent', restoreGrids);
    editor.on('NodeChange', restoreGrids);

    /* -----------------------------
     P ers*ist styles for export
     ----------------------------- */
    editor.on('GetContent', e=>{
        const temp = document.createElement('div');
        temp.appendChild(editor.dom.createFragment(e.content));

        temp.querySelectorAll(`[${GRID_ATTR}]`).forEach(wrapper=>{
            const grid = getGrid(wrapper);
            if(grid){
                grid.style.gridTemplateColumns = `repeat(auto-fit, minmax(${wrapper.dataset.minwidth}px, 1fr))`;
                grid.style.gap = wrapper.dataset.gap+'px';
                grid.style.padding = wrapper.dataset.gridPadding;
            }
            wrapper.style.background = wrapper.dataset.containerbg;
            wrapper.querySelectorAll(`[${ITEM_ATTR}]`).forEach(cell=>{
                cell.style.background = wrapper.dataset.cellbg;
                cell.style.padding = wrapper.dataset.cellPadding;
                cell.style.borderColor = wrapper.dataset.cellBorderColor;
                cell.style.borderWidth = wrapper.dataset.cellBorderWidth;
                cell.style.borderStyle = 'solid';
                cell.style.textAlign = wrapper.dataset.align==='center'?'center':wrapper.dataset.align==='right'?'right':'left';
            });
        });

        e.content = temp.innerHTML;
    });

});
