/* Client shell only. Core WordPress owns editor state, uploads and saves. */
(function () {
 'use strict';
 const header = document.querySelector('.df-inhalt-client-header');
 if (!header || !window.dfInhaltClient) return;
 if (window.wp?.domReady && window.wp?.data) {
  window.wp.domReady(() => { const preferences=window.wp.data.dispatch('core/preferences'); if(preferences?.set){preferences.set('core/edit-post','welcomeGuide',false);preferences.set('core/edit-post','welcomeGuideTemplate',false);} });
 }
 const back = header.querySelector('nav a:last-child');
 const key = 'df-inhalt-client-return';
 try {
  const supplied = new URLSearchParams(location.search).get('df_inhalt_return');
  if (supplied) {
   const url = new URL(supplied, location.origin);
   if (url.origin === location.origin && !url.pathname.includes('/wp-admin/')) sessionStorage.setItem(key, url.href);
  }
  const saved = sessionStorage.getItem(key);
  if (saved) {
   const url = new URL(saved);
   if (url.origin === location.origin && !url.pathname.includes('/wp-admin/')) { url.searchParams.set('df-inhalt-edit','1'); back.href = url.href; }
  }
 } catch (e) { /* Navigation still works without browser storage. */ }
 let replay = false;
 document.addEventListener('click', function (event) {
  if (replay) return;
  const target = event.target.closest('a.submitdelete, .row-actions .trash a, button.editor-post-trash, .editor-post-trash button');
  if (!target) return;
  event.preventDefault(); event.stopImmediatePropagation();
  const previous = document.activeElement;
  const layer = document.createElement('div'); layer.className = 'df-inhalt-client-dialog-backdrop';
  const dialog = document.createElement('section'); dialog.className = 'df-inhalt-client-dialog'; dialog.setAttribute('role','dialog');dialog.setAttribute('aria-modal','true');dialog.setAttribute('aria-labelledby','df-inhalt-client-trash-title');
  const title = document.createElement('h2'); title.id='df-inhalt-client-trash-title'; title.textContent=dfInhaltClient.question;
  const text = document.createElement('p'); text.textContent=dfInhaltClient.description;
  const actions = document.createElement('div');actions.className='df-inhalt-client-dialog-actions';
  const trash=document.createElement('button');trash.type='button';trash.className='df-inhalt-client-button df-inhalt-client-button--primary';trash.textContent=dfInhaltClient.trash;
  const keep=document.createElement('button');keep.type='button';keep.className='df-inhalt-client-button';keep.textContent=dfInhaltClient.keep;
  const close=()=>{layer.remove();previous?.focus();};
  keep.addEventListener('click',close);
  trash.addEventListener('click',()=>{close();replay=true;target.click();replay=false;});
  dialog.addEventListener('keydown',e=>{if(e.key==='Escape'){e.preventDefault();close();}if(e.key==='Tab'){if(e.shiftKey && document.activeElement===trash){e.preventDefault();keep.focus();}else if(!e.shiftKey && document.activeElement===keep){e.preventDefault();trash.focus();}}});
  actions.append(trash,keep);dialog.append(title,text,actions);layer.append(dialog);document.body.append(layer);keep.focus();
 },true);
}());
