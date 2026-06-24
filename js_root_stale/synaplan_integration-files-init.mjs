const __vite__mapDeps=(i,m=__vite__mapDeps,d=(m.f||(m.f=[window.OC.filePath('synaplan_integration', '', 'js/SummaryModal-DOFrkRFW.chunk.mjs'),window.OC.filePath('synaplan_integration', '', 'js/_plugin-vue_export-helper-Ju85sZ8E.chunk.mjs'),window.OC.filePath('synaplan_integration', '', 'js/translation-DoG5ZELJ-mZuXFx11.chunk.mjs'),window.OC.filePath('synaplan_integration', '', 'js/NcDialog-BG9t4Psg-Dn7KItfo.chunk.mjs'),window.OC.filePath('synaplan_integration', '', 'js/NcModal-DHryP_87-vyPTtZ7R.chunk.mjs'),window.OC.filePath('synaplan_integration', '', 'js/preload-helper-B2MwgvWF.chunk.mjs'),window.OC.filePath('synaplan_integration', '', 'js/NcRichText-DJlaHs_Q-DyiRUOGu.chunk.mjs'),window.OC.filePath('synaplan_integration', '', 'js/NcTextField.vue_vue_type_script_setup_true_lang-BxkYy7wv-4jlN9JTN.chunk.mjs'),window.OC.filePath('synaplan_integration', '', 'js/TranslateModal-z-__wANd.chunk.mjs'),window.OC.filePath('synaplan_integration', '', 'js/KnowledgeModal-D6PjgcYI.chunk.mjs')])))=>i.map(i=>d[i]);
import{g as b,_ as p}from"./preload-helper-B2MwgvWF.chunk.mjs";import{q as _,s as c,h as m}from"./translation-DoG5ZELJ-mZuXFx11.chunk.mjs";window._nc_files_scope??={},window._nc_files_scope.v4_0??={};const l=window._nc_files_scope.v4_0,E=b().setApp("@nextcloud/files").detectUser().build(),f=Object.freeze({NONE:0,READ:1,UPDATE:2,CREATE:4,WRITE:4,DELETE:8,SHARE:16,ALL:31});var k=class extends EventTarget{dispatchTypedEvent(n,e){return super.dispatchEvent(e)}};class C extends k{}function I(){return l.registry??=new C,l.registry}const H=Object.freeze({DEFAULT:"default",HIDDEN:"hidden"});function V(n){if(A(n),l.fileActions??=new Map,l.fileActions.has(n.id)){E.error(`FileAction ${n.id} already registered`,{action:n});return}l.fileActions.set(n.id,n),I().dispatchTypedEvent("register:action",new CustomEvent("register:action",{detail:n}))}function A(n){if(!n.id||typeof n.id!="string")throw new Error("Invalid id");if(!n.displayName||typeof n.displayName!="function")throw new Error("Invalid displayName function");if("title"in n&&typeof n.title!="function")throw new Error("Invalid title function");if(!n.iconSvgInline||typeof n.iconSvgInline!="function")throw new Error("Invalid iconSvgInline function");if(!n.exec||typeof n.exec!="function")throw new Error("Invalid exec function");if("enabled"in n&&typeof n.enabled!="function")throw new Error("Invalid enabled function");if("execBatch"in n&&typeof n.execBatch!="function")throw new Error("Invalid execBatch function");if("order"in n&&typeof n.order!="number")throw new Error("Invalid order");if(n.destructive!==void 0&&typeof n.destructive!="boolean")throw new Error("Invalid destructive flag");if("parent"in n&&typeof n.parent!="string")throw new Error("Invalid parent");if(n.default&&!Object.values(H).includes(n.default))throw new Error("Invalid default");if("inline"in n&&typeof n.inline!="function")throw new Error("Invalid inline function");if("renderInline"in n&&typeof n.renderInline!="function")throw new Error("Invalid renderInline function");if("hotkey"in n&&n.hotkey!==void 0){if(typeof n.hotkey!="object")throw new Error("Invalid hotkey configuration");if(typeof n.hotkey.key!="string"||!n.hotkey.key)throw new Error("Missing or invalid hotkey key");if(typeof n.hotkey.description!="string"||!n.hotkey.description)throw new Error("Missing or invalid hotkey description")}}function u(n,e={},a={}){let{container:o}=a;"container"in e&&typeof e.container=="string"&&(o??=e.container);const i=(typeof o=="string"&&document.querySelector(o)||document.body).appendChild(document.createElement("div"));return new Promise((w,h)=>{const r=_(n,{...e,container:null,onClose(...d){const v=d.length>1?d:d[0];r.unmount(),i.remove(),w(v)},"onVue:unmounted":()=>{r.unmount(),i.remove(),h(new Error("Dialog was unmounted without close event"))}});r.mount(i)})}const L='<svg xmlns="http://www.w3.org/2000/svg" id="mdi-text-box-search-outline" viewBox="0 0 24 24"><path d="M15.5,12C18,12 20,14 20,16.5C20,17.38 19.75,18.21 19.31,18.9L22.39,22L21,23.39L17.88,20.32C17.19,20.75 16.37,21 15.5,21C13,21 11,19 11,16.5C11,14 13,12 15.5,12M15.5,14A2.5,2.5 0 0,0 13,16.5A2.5,2.5 0 0,0 15.5,19A2.5,2.5 0 0,0 18,16.5A2.5,2.5 0 0,0 15.5,14M5,3H19C20.11,3 21,3.89 21,5V13.03C20.5,12.23 19.81,11.54 19,11V5H5V19H9.5C9.81,19.75 10.26,20.42 10.81,21H5C3.89,21 3,20.11 3,19V5C3,3.89 3.89,3 5,3M7,7H17V9H7V7M7,11H12.03C11.23,11.5 10.54,12.19 10,13H7V11M7,15H9.17C9.06,15.5 9,16 9,16.5V17H7V15Z" /></svg>';function g(...n){const e=n[0];return e?.nodes?e.nodes:Array.isArray(e)?e:[]}function x(...n){const e=n[0];return e?.nodes?.[0]?e.nodes[0]:e?.fileid!==void 0?e:null}const M=["text/","application/pdf","application/json","application/xml","application/rtf","application/msword","application/vnd.openxmlformats-officedocument","application/vnd.oasis.opendocument"],S={id:"synaplan:summarize",displayName:()=>m("synaplan_integration","Summarize with Synaplan"),iconSvgInline:()=>L,enabled(...n){const e=g(...n);if(e.length!==1)return!1;const a=e[0];if((a.permissions&f.READ)===0)return!1;const o=a.mime??"";return M.some(i=>o.startsWith(i))},async exec(...n){const e=x(...n);return e&&await u(c(()=>p(()=>import("./SummaryModal-DOFrkRFW.chunk.mjs"),__vite__mapDeps([0,1,2,3,4,5,6,7]),import.meta.url)),{fileId:e.fileid,fileName:e.basename}),null},order:50},$='<svg xmlns="http://www.w3.org/2000/svg" id="mdi-translate" viewBox="0 0 24 24"><path d="M12.87,15.07L10.33,12.56L10.36,12.53C12.1,10.59 13.34,8.36 14.07,6H17V4H10V2H8V4H1V6H12.17C11.5,7.92 10.44,9.75 9,11.35C8.07,10.32 7.3,9.19 6.69,8H4.69C5.42,9.63 6.42,11.17 7.67,12.56L2.58,17.58L4,19L9,14L12.11,17.11L12.87,15.07M18.5,10H16.5L12,22H14L15.12,19H19.87L21,22H23L18.5,10M15.88,17L17.5,12.67L19.12,17H15.88Z" /></svg>',D=["text/","application/pdf","application/json","application/xml","application/rtf","application/msword","application/vnd.openxmlformats-officedocument","application/vnd.oasis.opendocument"],N={id:"synaplan:translate",displayName:()=>m("synaplan_integration","Translate with Synaplan"),iconSvgInline:()=>$,enabled(...n){const e=g(...n);if(e.length!==1)return!1;const a=e[0];if((a.permissions&f.READ)===0)return!1;const o=a.mime??"";return D.some(i=>o.startsWith(i))},async exec(...n){const e=x(...n);return e&&await u(c(()=>p(()=>import("./TranslateModal-z-__wANd.chunk.mjs"),__vite__mapDeps([8,1,2,3,4,5,6,7]),import.meta.url)),{fileId:e.fileid,fileName:e.basename}),null},order:51},T='<svg xmlns="http://www.w3.org/2000/svg" id="mdi-database-plus-outline" viewBox="0 0 24 24"><path d="M20 13.09V7C20 4.79 16.42 3 12 3S4 4.79 4 7V17C4 19.21 7.59 21 12 21C12.46 21 12.9 21 13.33 20.94C13.12 20.33 13 19.68 13 19L13 18.95C12.68 19 12.35 19 12 19C8.13 19 6 17.5 6 17V14.77C7.61 15.55 9.72 16 12 16C12.65 16 13.27 15.96 13.88 15.89C14.93 14.16 16.83 13 19 13C19.34 13 19.67 13.04 20 13.09M18 12.45C16.7 13.4 14.42 14 12 14S7.3 13.4 6 12.45V9.64C7.47 10.47 9.61 11 12 11S16.53 10.47 18 9.64V12.45M12 9C8.13 9 6 7.5 6 7S8.13 5 12 5 18 6.5 18 7 15.87 9 12 9M23 18V20H20V23H18V20H15V18H18V15H20V18H23Z" /></svg>',j=["text/","application/pdf","application/json","application/xml","application/rtf","application/msword","application/vnd.ms-excel","application/vnd.ms-powerpoint","application/vnd.openxmlformats-officedocument","application/vnd.oasis.opendocument"],R={id:"synaplan:knowledge",displayName:()=>m("synaplan_integration","Add to AI Knowledge"),iconSvgInline:()=>T,enabled(...n){const e=g(...n);if(e.length!==1)return!1;const a=e[0];if((a.permissions&f.READ)===0)return!1;const o=a.mime??"";return j.some(i=>o.startsWith(i))},async exec(...n){const e=x(...n);return e&&await u(c(()=>p(()=>import("./KnowledgeModal-D6PjgcYI.chunk.mjs"),__vite__mapDeps([9,1,2,3,4,5]),import.meta.url)),{fileId:e.fileid,fileName:e.basename}),null},order:52},z=[".synaplan-summary-modal",".synaplan-translate-modal",".synaplan-chat-modal",".synaplan-knowledge-modal"];function t(n){return z.map(e=>`.modal-mask:has(${e})${n}`).join(`,
`)}const O=`
${t("")} {
	position: fixed;
	z-index: 9998;
	top: 0;
	left: 0;
	width: 100%;
	height: 100%;
	background-color: rgba(0, 0, 0, 0.5);
	display: block;
}

${t("")},
${t(" *")} {
	box-sizing: border-box;
}

${t(" .modal-wrapper")} {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 100%;
	height: 100%;
}

${t(" .modal-container")} {
	background: var(--color-main-background, #fff);
	border-radius: var(--border-radius-large, 10px);
	padding: 0;
	margin: 20px;
	max-height: calc(100vh - 40px);
	max-width: 900px;
	width: 100%;
	overflow: auto;
	box-shadow: 0 2px 20px rgba(0, 0, 0, 0.3);
}

${t(" .modal-container--normal")} {
	max-width: 600px;
}

${t(" .modal-container--small")} {
	max-width: 400px;
}

${t(" .modal-container__close")} {
	position: absolute;
	right: 4px;
	top: 4px;
	z-index: 1;
}

${t(" .dialog__wrapper")} {
	display: flex;
	flex-direction: column;
}

${t(" .dialog__content")} {
	padding: 12px 20px;
	flex: 1 1 auto;
	overflow: auto;
}

${t(" .dialog__name")} {
	text-align: center;
	padding: 12px 20px 0;
	margin: 0;
}

${t(" .dialog__actions")} {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	padding: 8px 20px 12px;
}
`;let y=!1;function B(){if(y)return;y=!0;const n=document.createElement("style");n.setAttribute("data-source","synaplan-modal-fix"),n.textContent=O,document.head.appendChild(n)}function s(n){V(n);const e=window;typeof e._nc_fileactions>"u"&&(e._nc_fileactions=[]);const a=e._nc_fileactions;a.find(o=>o.id===n.id)||a.push(n)}B(),P(),s(S),s(N),s(R);function P(){const n=`
.synaplan-summary-modal .options,
.synaplan-translate-modal .options {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.synaplan-summary-modal .field,
.synaplan-translate-modal .field {
	display: flex;
	align-items: center;
	gap: 12px;
}

.synaplan-summary-modal .field label,
.synaplan-translate-modal .field label {
	flex: 0 0 150px;
	font-weight: bold;
	text-align: right;
	white-space: nowrap;
	overflow: visible;
}

.synaplan-summary-modal .field .v-select,
.synaplan-translate-modal .field .v-select {
	flex: 1;
	min-width: 0;
}

.synaplan-summary-modal,
.synaplan-translate-modal {
	padding: 16px 0;
	min-height: 120px;
}

.synaplan-summary-modal .loading,
.synaplan-translate-modal .loading {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 12px;
	padding: 24px;
}

.synaplan-chat-modal {
	display: flex;
	flex-direction: column;
	min-height: 300px;
	max-height: 500px;
}

.synaplan-chat-modal .messages {
	flex: 1;
	overflow-y: auto;
	padding: 8px 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
	min-height: 0;
}

.synaplan-chat-modal .message {
	max-width: 85%;
	padding: 10px 14px;
	border-radius: 12px;
	line-height: 1.5;
}

.synaplan-chat-modal .message.user {
	align-self: flex-end;
	background: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element-text, #fff);
}

.synaplan-chat-modal .message.assistant {
	align-self: flex-start;
	background: var(--color-background-dark, #2a2a2a);
}

.synaplan-chat-modal .message-content {
	white-space: pre-wrap;
	word-break: break-word;
}

.synaplan-chat-modal .chat-input {
	display: flex;
	gap: 10px;
	padding-top: 12px;
	border-top: 1px solid var(--color-border, #444);
	margin-top: 8px;
	align-items: center;
}

.synaplan-chat-modal .chat-input .input-field {
	flex: 1;
	min-width: 0;
}

.synaplan-chat-modal .chat-input input {
	width: 100% !important;
}

/* Knowledge modal styles */
.synaplan-knowledge-modal {
	padding: 8px 0;
}

.synaplan-knowledge-modal .description {
	color: var(--color-text-maxcontrast, #767676);
	margin: 0 0 16px;
	line-height: 1.5;
}

.synaplan-knowledge-modal .field {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 16px;
}

.synaplan-knowledge-modal .field-label {
	flex: 0 0 140px;
	font-weight: 600;
	color: var(--color-main-text, #222);
}

.synaplan-knowledge-modal .field .v-select {
	flex: 1;
	min-width: 0;
}

.synaplan-knowledge-modal .file-info {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 10px 14px;
	background: var(--color-background-dark, #f5f5f5);
	border-radius: 8px;
	margin-top: 8px;
}

.synaplan-knowledge-modal .success-state {
	text-align: center;
	padding: 24px 0;
}

.synaplan-knowledge-modal .success-icon {
	font-size: 3em;
	color: var(--color-success, #46ba61);
	margin-bottom: 12px;
}

.synaplan-knowledge-modal .success-text {
	font-size: 1.15em;
	font-weight: 600;
	margin: 0 0 20px;
}

.synaplan-knowledge-modal .success-details {
	text-align: left;
	max-width: 300px;
	margin: 0 auto;
}

.synaplan-knowledge-modal .detail-row {
	display: flex;
	justify-content: space-between;
	padding: 6px 0;
	border-bottom: 1px solid var(--color-border-dark, #e0e0e0);
}

.synaplan-knowledge-modal .detail-label {
	color: var(--color-text-maxcontrast, #767676);
}

.synaplan-knowledge-modal .detail-value {
	font-weight: 600;
}
`,e=document.createElement("style");e.setAttribute("data-source","synaplan-form-styles"),e.textContent=n,document.head.appendChild(e)}
//# sourceMappingURL=synaplan_integration-files-init.mjs.map
