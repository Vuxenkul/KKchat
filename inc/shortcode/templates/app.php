<?php if (!defined('ABSPATH')) exit; ?>
    <header class="header">
      <div class="h-left"><div class="title">KKchatten Beta 0.8.3 </div></div>
      <div>
        <span class="small">Inloggad som <b><?= kkchat_html_esc($me_nm) ?></b><?= $is_admin ? ' — <b>Admin</b>' : '' ?></span>
        <a class="iconbtn" href="#" id="kk-logout" style="margin-left:8px">Logga ut</a>
      </div>
    </header>
<button
  type="button"
  id="kk-sideOpenBtn"
  class="side-toggle standalone"
  aria-haspopup="dialog"
  aria-expanded="false">
  <span class="material-symbols-rounded" aria-hidden="true">search</span> Sök & personer
</button>

    <div class="kk-layout">
      <aside class="sidebar" id="kk-sidebar">

          <!-- Wrapped original content -->
          <div id="kk-leftWrap">
            <div class="lefttabs" id="kk-leftTabs">
  <button type="button" data-view="users" aria-selected="true">
    <span class="material-symbols-rounded" aria-hidden="true">person</span> <span class="badge" id="kk-countUsers">0</span>
  </button>
  <button type="button" data-view="rooms" aria-selected="false">
    <span class="material-symbols-rounded" aria-hidden="true">chat</span> <span class="badge" id="kk-countRooms">0</span>
  </button>
  <button type="button" data-view="dms" aria-selected="false">
    <span class="material-symbols-rounded" aria-hidden="true">mail</span> <span class="badge" id="kk-countDMs">0</span>
  </button>
    <?php if ($is_admin): ?>
  <button type="button" data-view="reports" aria-selected="false">
    <span class="material-symbols-rounded" aria-hidden="true">flag</span> <span class="badge" id="kk-countReports">0</span>
  </button>
  <?php endif; ?>
</div>

            <div class="leftview" id="kk-lvUsers" active>
              <div class="people">
                <div class="user-search-row">
                  <input id="kk-uSearch" class="user-search-input" placeholder="Sök person…" autocomplete="off">
                  <button
                    type="button"
                    id="kk-userFilterBtn"
                    class="user-filter-btn"
                    aria-haspopup="true"
                    aria-expanded="false"
                    aria-controls="kk-userFilterMenu"
                    title="Filtrera på kön"
                    aria-label="Filtrera användare på kön">
                    <span class="user-filter-ico" aria-hidden="true"></span>
                  </button>
                </div>
                <div class="user-filter-menu" id="kk-userFilterMenu" role="group" aria-label="Filtrera på kön" hidden>
                  <fieldset>
                    <legend>Visa kön</legend>
                    <label for="kk-filter-man"><input type="checkbox" id="kk-filter-man" value="man"> Man</label>
                    <label for="kk-filter-woman"><input type="checkbox" id="kk-filter-woman" value="woman"> Kvinna</label>
                    <label for="kk-filter-couple"><input type="checkbox" id="kk-filter-couple" value="couple"> Par</label>
                    <label for="kk-filter-trans-mtf"><input type="checkbox" id="kk-filter-trans-mtf" value="trans-mtf"> Trans (MTF)</label>
                    <label for="kk-filter-trans-ftm"><input type="checkbox" id="kk-filter-trans-ftm" value="trans-ftm"> Trans (FTM)</label>
                    <label for="kk-filter-nonbinary"><input type="checkbox" id="kk-filter-nonbinary" value="nonbinary"> Icke-binär / annat</label>
                    <label for="kk-filter-unknown"><input type="checkbox" id="kk-filter-unknown" value="unknown"> Okänt / utan kön</label>
                  </fieldset>
                  <div class="filter-actions">
                    <button type="button" id="kk-userFilterClear" class="user-filter-clear">Rensa filter</button>
                  </div>
                </div>
              </div>
              <div id="kk-userList" class="users"></div>
            </div>

            <div class="leftview" id="kk-lvRooms">
              <div id="kk-roomList" class="users"></div>
            </div>

            <div class="leftview" id="kk-lvDMs">
              <div id="kk-dmSideList" class="users"></div>
            </div>
              <?php if ($is_admin): ?>
  <div class="leftview" id="kk-lvReports">
    <div id="kk-reportList" class="users"></div>
    <div class="people" style="padding:8px">
      <button id="kk-reportRefresh" type="button" class="iconbtn" aria-label="Uppdatera rapporter"><span class="material-symbols-rounded" aria-hidden="true">refresh</span></button>
    </div>
  </div>
  <?php endif; ?>

          </div>
        </aside>

<!-- Mobile full-screen overlay for Sök & personer -->
<div id="kkchat-sideOverlay" aria-hidden="true">
  <div class="side-sheet" role="dialog" aria-modal="true" aria-labelledby="kk-sideTitle" tabindex="-1">
    <div class="side-head">
      <strong id="kk-sideTitle">Sök & personer</strong>
      <button type="button" class="iconbtn" id="kk-sideClose">X</button>
    </div>
    <div class="side-content" id="kk-sideContent"></div>
  </div>
</div>

      <main class="chat">

        <nav class="tabs" id="kk-roomTabs"></nav>

        <!-- Unified chat view for BOTH rooms and DMs -->
        <section id="vPublic" class="view" active>
          <div class="msgwrap">
            <ul id="kk-pubList" class="list" data-last="-1"></ul>
            <button id="kk-jumpBottom" class="fab" type="button" aria-label="Hoppa till botten"><span class="material-symbols-rounded" aria-hidden="true">arrow_downward</span></button>
            <div class="inputbar" id="kk-composer">
              <div class="reply-banner" id="kk-replyBanner" hidden>
                <div class="reply-banner__body">
                  <span class="material-symbols-rounded" aria-hidden="true">reply</span>
                  <div class="reply-banner__text">
                    <strong id="kk-replyBannerName"></strong>
                    <span id="kk-replyBannerExcerpt"></span>
                  </div>
                </div>
                <button type="button" class="reply-banner__close" id="kk-replyCancel" aria-label="Avbryt svar">
                  <span class="material-symbols-rounded" aria-hidden="true">close</span>
                </button>
              </div>
              <form id="kk-pubForm" class="inputbar__form">
                <input type="hidden" name="csrf_token" value="<?= kkchat_html_esc($session_csrf) ?>">
                <input type="hidden" name="reply_to_id" id="kk-replyTo" value="">
                <input type="hidden" name="reply_excerpt" id="kk-replyExcerpt" value="">
                <div class="input-actions" data-attach-root>
                  <button
                    type="button"
                    class="iconbtn input-actions-toggle"
                    id="kk-attachToggle"
                    data-attach-toggle
                    title="Fler alternativ"
                    aria-haspopup="menu"
                    aria-expanded="false"
                  ><span class="material-symbols-rounded" aria-hidden="true">add</span></button>
                  <div class="input-actions-menu" data-attach-menu hidden role="menu" aria-hidden="true">
                    <button type="button" class="input-actions-item" id="kk-pubUpBtn" data-attach-item role="menuitem"><span class="material-symbols-rounded" aria-hidden="true">image</span> Ladda upp bild</button>
                    <button type="button" class="input-actions-item" id="kk-pubCamBtn" data-attach-item role="menuitem"><span class="material-symbols-rounded" aria-hidden="true">photo_camera</span> Öppna kamera</button>
                    <button type="button" class="input-actions-item" id="kk-mentionBtn" data-attach-item role="menuitem"><span class="material-symbols-rounded" aria-hidden="true">alternate_email</span> Nämn någon</button>
                  </div>
                </div>

                <!-- Hidden inputs (one for upload picker, one that hints camera on mobile) -->
                <input type="file" accept="image/*" id="kk-pubImg" style="display:none">
                <input type="file" accept="image/*" capture="environment" id="kk-pubCam" style="display:none">

                <textarea name="content" placeholder="Skriv ett meddelande…" autocomplete="off"></textarea>
                <button aria-label="Skicka meddelande"><span class="material-symbols-rounded" aria-hidden="true">send</span><span class="sr-only">Skicka</span></button>
                <div id="kk-mentionBox" class="mentionbox" role="listbox" aria-label="Mention suggestions"></div>

              </form>
            </div>
          </div>
        </section>
        <!-- (Old vDM view removed; DMs now use the same view as rooms) -->
      </main>
    </div>

    <!-- Admin log overlay -->
    <?php if ($is_admin): ?>
    <div id="kk-logPanel" class="logpanel" role="dialog" aria-modal="true" aria-labelledby="kk-logTitle">
      <div class="logbox">
        <div class="loghead">
          <strong id="kk-logTitle">Logg: <span id="kk-logUser"></span></strong>
          <div><button id="kk-logClose" class="iconbtn" type="button">Stäng</button></div>
        </div>
        <ul id="kk-logList" class="loglist"></ul>
        <div class="logfoot">
          <button id="kk-logMore" class="tab" type="button">Ladda fler</button>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Image preview overlay -->
<div id="kk-imgPanel" class="imgpanel" role="dialog" aria-modal="true" aria-labelledby="kk-imgCap">
  <div class="imgbox">
    <img id="kk-imgView" src="" alt="">
    <div class="imgfoot">
      <div id="kk-imgCap"></div>
      <div class="btns">
        <button id="kk-img-act-dm"     class="iconbtn" type="button" title="Skicka DM"            style="display:none"><span class="material-symbols-rounded" aria-hidden="true">forward_to_inbox</span> Skicka DM</button>
        <button id="kk-img-act-report" class="iconbtn" type="button" title="Rapportera"           style="display:none"><span class="material-symbols-rounded" aria-hidden="true">report</span> Rapportera</button>
        <button id="kk-img-act-block"  class="iconbtn" type="button" title="Blockera/Avblockera"  style="display:none"><span class="material-symbols-rounded" aria-hidden="true">block</span> Blockera</button>

        <a id="kk-imgOpen" class="iconbtn" href="#" target="_blank" rel="noopener" style="display:none">Öppna original</a>
        <button id="kk-imgClose" class="iconbtn" type="button">Stäng</button>
      </div>
    </div>
  </div>
</div>
<!-- Webcam capture modal (desktop fallback) -->
<div id="kk-camModal" role="dialog" aria-modal="true" aria-labelledby="kk-camTitle">
  <div id="kk-camBox">
    <strong id="kk-camTitle">Kameravy</strong>
    <video id="kk-camVideo" autoplay playsinline muted></video>
    <div id="kk-camBtns">
      <button id="kk-camFlip" type="button" title="Byt kamera"><span class="material-symbols-rounded" aria-hidden="true">refresh</span></button>
      <button id="kk-camShot" type="button" title="Ta bild"><span class="material-symbols-rounded" aria-hidden="true">photo_camera</span> Ta bild</button>
      <button id="kk-camCancel" type="button" title="Avbryt">Stäng</button>
    </div>
  </div>
</div>

<!-- Message action sheet -->
<div id="kk-msgSheet" role="dialog" aria-modal="true" aria-labelledby="kk-msgTitle">
  <div class="box">
    <div class="head">
      <strong id="kk-msgTitle">Åtgärder</strong>
      <button type="button" class="iconbtn" id="kk-msgClose">Stäng</button>
    </div>
    <div class="row">
      <button id="kk-act-dm" type="button"><span class="material-symbols-rounded" aria-hidden="true">forward_to_inbox</span> Skicka DM</button>
      <button id="kk-act-report" type="button"><span class="material-symbols-rounded" aria-hidden="true">report</span> Rapportera</button>
      <button id="kk-act-block" type="button"><span class="material-symbols-rounded" aria-hidden="true">block</span> Blockera</button>

    </div>
            <?php if ($is_admin): ?>
          <div class="row">
            <button id="kk-act-hide" type="button"><span class="material-symbols-rounded" aria-hidden="true">visibility_off</span> Dölj meddelande</button>
          </div>
        <?php endif; ?>
  </div>
</div>

<!-- Explicit label modal -->
<div id="kk-explicitModal" class="kk-explicit-modal" role="dialog" aria-modal="true" aria-labelledby="kk-explicitTitle">
  <form class="kk-explicit-box" id="kk-explicitForm">
    <strong id="kk-explicitTitle">Godkänn uppladdning</strong>
    <p class="kk-explicit-text">Ladda bara upp lagliga 18+ bilder med samtycke; XXX är förvalt – avmarkera bara om bilden är SFW/inte av sexuell natur - felaktig märkning kan leda till ban.</p>
    <label class="kk-explicit-check"><input type="checkbox" id="kk-explicitCheck" checked> XXX</label>
    <div class="kk-explicit-name" id="kk-explicitName"></div>
    <div class="kk-explicit-actions">
      <button type="button" class="iconbtn" id="kk-explicitCancel">Avbryt</button>
      <button type="submit" class="iconbtn primary" id="kk-explicitConfirm">Bekräfta &amp; ladda upp</button>
    </div>
  </form>
</div>

<!-- Multi-tab takeover modal -->
<div
  id="kk-multiTabModal"
  class="kk-multitab"
  role="dialog"
  aria-modal="true"
  aria-labelledby="kk-multiTabTitle"
  aria-hidden="true"
  hidden
>
  <div class="kk-multitab__box">
    <h2 id="kk-multiTabTitle">Chatten är redan öppen</h2>
    <p id="kk-multiTabDesc">Chatten är redan öppen i en annan flik. Tryck på ”Använd chatten här” om du vill fortsätta i den här fliken.</p>
    <button type="button" id="kk-multiTabUseHere" class="kk-multitab__btn">Använd chatten här</button>
  </div>
</div>

    <audio id="kk-notifSound"   src="<?= $audio ?>"         preload="auto"></audio>
    <audio id="kk-mentionSound" src="<?= $mention_audio ?>" preload="auto"></audio>
        <audio id="kk-reportSound"  src="<?= $report_audio ?>"  preload="auto"></audio>

    <div id="kk-toast" class="toast" role="status" aria-live="polite"></div>

<script>
(function(){
  const API = "<?= $ns ?>";
  const CSRF = "<?= $csrf ?>";
  const REST_NONCE = "<?= $rest_nonce ?>";
  const ME_ID = <?= (int)$me_id ?>;
  const ME_NM = <?= json_encode($me_nm) ?>;
  const OPEN_DM_USER = <?= $open_dm ?>;
  const IS_ADMIN = <?= $is_admin ? 'true' : 'false' ?>;
  const ADMIN_LINKS = <?= wp_json_encode($admin_links) ?>;
  const HAS_ADMIN_TOOLS = Array.isArray(ADMIN_LINKS) && ADMIN_LINKS.length > 0;
  const GENDER_ICON_BASE = <?= json_encode($gender_icon_base) ?>;
  const POLL_SETTINGS = Object.freeze(<?= wp_json_encode($poll_settings) ?>);

  const $ = s => document.querySelector(s);
  const pubList = $('#kk-pubList');
  const pubForm = $('#kk-pubForm');
  const replyToInput = document.getElementById('kk-replyTo');
  const replyExcerptInput = document.getElementById('kk-replyExcerpt');
  const replyBanner = document.getElementById('kk-replyBanner');
  const replyBannerName = document.getElementById('kk-replyBannerName');
  const replyBannerExcerpt = document.getElementById('kk-replyBannerExcerpt');
  const replyCancel = document.getElementById('kk-replyCancel');
  const notif   = $('#kk-notifSound');
  const toast   = $('#kk-toast');
  const jumpBtn = $('#kk-jumpBottom');

  const userListEl = $('#kk-userList');
  const userSearch = $('#kk-uSearch');
  const userFilterBtn   = document.getElementById('kk-userFilterBtn');
  const userFilterMenu  = document.getElementById('kk-userFilterMenu');
  const userFilterClear = document.getElementById('kk-userFilterClear');
  const userFilterInputs= userFilterMenu ? Array.from(userFilterMenu.querySelectorAll('input[type="checkbox"]')) : [];
  const activeGenderFilters = new Set();
  const logoutBtn  = $('#kk-logout');
  const roomTabs   = document.getElementById('kk-roomTabs');
  const chatRoot   = document.getElementById('kkchat-root');
  const multiTabModal = document.getElementById('kk-multiTabModal');
  const multiTabDesc  = document.getElementById('kk-multiTabDesc');
  const multiTabUseHere = document.getElementById('kk-multiTabUseHere');

  const MATERIAL_ICON_CLASS = 'material-symbols-rounded';
  function iconMarkup(name, { filled = false } = {}) {
    const cls = filled ? `${MATERIAL_ICON_CLASS} icon-fill` : MATERIAL_ICON_CLASS;
    return `<span class="${cls}" aria-hidden="true">${name}</span>`;
  }

  function dispatchComposerChange(){
    try {
      document.dispatchEvent(new CustomEvent('kkchat:composer-change'));
    } catch (_) {
      if (typeof document.createEvent === 'function') {
        try {
          const ev = document.createEvent('CustomEvent');
          if (ev && typeof ev.initCustomEvent === 'function') {
            ev.initCustomEvent('kkchat:composer-change', false, false, undefined);
            document.dispatchEvent(ev);
          }
        } catch (_) {}
      }
    }
  }

  const MESSAGE_INDEX = new Map();
  const MESSAGE_INDEX_LIMIT = 800;
  let COMPOSER_REPLY = null;

  multiTabUseHere?.addEventListener('click', (ev)=>{
    ev.preventDefault();
    claimActiveTab({ forceCold: true });
  });

  const handleActivityEvent = () => noteUserActivity();
  function addActivityListener(target, type, opts){
    if (!target || typeof target.addEventListener !== 'function') return;
    try {
      target.addEventListener(type, handleActivityEvent, opts);
    } catch (_) {
      target.addEventListener(type, handleActivityEvent);
    }
  }

  addActivityListener(document, 'pointerdown', { passive: true });
  addActivityListener(document, 'touchstart', { passive: true });
  addActivityListener(document, 'touchmove', { passive: true });
  addActivityListener(document, 'wheel', { passive: true });
  addActivityListener(document, 'mousemove', { passive: true });
  addActivityListener(document, 'keydown');
  addActivityListener(document, 'click');
  addActivityListener(document, 'input');
  addActivityListener(window, 'scroll', { passive: true });
  addActivityListener(pubList, 'scroll', { passive: true });

    function safeEsc(s){
      const t = s == null ? '' : String(s);

      if (typeof esc === 'function') {
        try { return esc(t); } catch(_) {}
      }

      return t.replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
    }

    function retitleDmTabs(){
      try{
        if (!roomTabs) return;
        const tabs = roomTabs.querySelectorAll('.tab[data-dm]');
        tabs.forEach(btn=>{
          const id = Number(btn.dataset.dm || btn.getAttribute('data-dm'));
          if (!id) return;

          const nm = nameById(id);
          const offline = !isOnline(id);

          const badgeEl = btn.querySelector('.badge');
            const closeEl = btn.querySelector('.tab-close');
            const muteEl  = btn.querySelector('.tab-mute');
            const badge = badgeEl ? badgeEl.outerHTML : '';
            const close = closeEl ? closeEl.outerHTML : '';
            const mute  = muteEl ? muteEl.outerHTML : dmMuteHTML(id);
            
            const label = offline ? `<span class="name-offline">${safeEsc(nm)}</span>` : safeEsc(nm);
            
            btn.innerHTML = `${mute}${label}${badge}${close}`;


          if (offline) btn.setAttribute('data-offline','1'); else btn.removeAttribute('data-offline');
        });
      }catch(_){}
    }

    const _tabsObserver = new MutationObserver(()=>retitleDmTabs());
    if (roomTabs) _tabsObserver.observe(roomTabs, {childList:true});

    retitleDmTabs();


  function updateFilterButtonState(){
    if (!userFilterBtn) return;
    const hasQuery = !!(userSearch && (userSearch.value||'').trim().length);
    const active = activeGenderFilters.size > 0 || hasQuery;    userFilterBtn.dataset.active = active ? '1' : '0';
    userFilterBtn.setAttribute('aria-pressed', active ? 'true' : 'false');
  }

  let isFilterMenuOpen = false;
  function setFilterMenuVisible(show){
    if (!userFilterMenu || !userFilterBtn) return;
    const wantOpen = !!show;
    isFilterMenuOpen = wantOpen;
    userFilterMenu.hidden = !wantOpen;
    userFilterBtn.setAttribute('aria-expanded', wantOpen ? 'true' : 'false');
    if (wantOpen) {
      requestAnimationFrame(()=>{
        const first = userFilterInputs.find(input => !input.disabled);
        first?.focus();
      });
    }
  }

  userFilterBtn?.addEventListener('click', (ev)=>{
    ev.preventDefault();
    setFilterMenuVisible(!isFilterMenuOpen);
  });

  document.addEventListener('click', (ev)=>{
    if (!isFilterMenuOpen) return;
    const t = ev.target;
    if (typeof Node !== 'undefined' && t instanceof Node) {
      if (userFilterBtn?.contains(t) || userFilterMenu?.contains(t)) return;
    }
    setFilterMenuVisible(false);
  });

  document.addEventListener('keydown', (ev)=>{
    if (!isFilterMenuOpen) return;
    if (ev.key === 'Escape') {
      setFilterMenuVisible(false);
      userFilterBtn?.focus();
    }
  });

  userFilterInputs.forEach(input => {
    input.addEventListener('change', ()=>{
      const val = input.value;
      if (!val) return;
      if (input.checked) activeGenderFilters.add(val);
      else activeGenderFilters.delete(val);
      updateFilterButtonState();
      renderUsers();
    });
  });

  userFilterClear?.addEventListener('click', ()=>{
    activeGenderFilters.clear();
    userFilterInputs.forEach(input => { input.checked = false; });
    updateFilterButtonState();
    setFilterMenuVisible(false);
    renderUsers();
  });

  updateFilterButtonState();


  const leftTabs     = document.getElementById('kk-leftTabs');
  const lvUsers      = document.getElementById('kk-lvUsers');
  const lvRooms      = document.getElementById('kk-lvRooms');
  const lvDMs        = document.getElementById('kk-lvDMs');
  const roomsListEl  = document.getElementById('kk-roomList');
  const dmSideListEl = document.getElementById('kk-dmSideList');
  const countUsersEl = document.getElementById('kk-countUsers');
  const countRoomsEl = document.getElementById('kk-countRooms');
  const countDMsEl   = document.getElementById('kk-countDMs');
  const lvReports       = document.getElementById('kk-lvReports');
  const reportListEl    = document.getElementById('kk-reportList');
  const reportRefreshBtn= document.getElementById('kk-reportRefresh');
  const countReportsEl  = document.getElementById('kk-countReports');
  const REPORT_REASON_PLACEHOLDER = 'Skriv anledning här';

  // State for reports
  let OPEN_REPORTS_COUNT = 0;
  let LAST_REPORT_MAX_ID = 0; // rising-edge anchor

// New buttons/inputs
const pubCamBtn = document.getElementById('kk-pubCamBtn');
const pubUpBtn  = document.getElementById('kk-pubUpBtn');
const pubImgInp = document.getElementById('kk-pubImg');
const pubCamInp = document.getElementById('kk-pubCam');
const pubTA     = pubForm?.querySelector('textarea');
const mentionBtn = document.getElementById('kk-mentionBtn');

const attachRoot   = pubForm?.querySelector('[data-attach-root]');
const attachToggle = attachRoot?.querySelector('[data-attach-toggle]');
const attachMenu   = attachRoot?.querySelector('[data-attach-menu]');
let ATTACH_MENU_OPEN = false;

function setAttachmentMenu(open) {
  ATTACH_MENU_OPEN = !!open;
  if (!attachRoot) return;

  attachRoot.classList.toggle('is-open', ATTACH_MENU_OPEN);

  if (attachMenu) {
    if (ATTACH_MENU_OPEN) attachMenu.removeAttribute('hidden');
    else attachMenu.setAttribute('hidden', '');
    attachMenu.setAttribute('aria-hidden', ATTACH_MENU_OPEN ? 'false' : 'true');
  }

  attachToggle?.setAttribute('aria-expanded', ATTACH_MENU_OPEN ? 'true' : 'false');
}

function toggleAttachmentMenu() {
  setAttachmentMenu(!ATTACH_MENU_OPEN);
}

function closeAttachmentMenu() {
  setAttachmentMenu(false);
}

attachToggle?.addEventListener('click', (e) => {
  if (attachToggle.disabled) return;
  e.preventDefault();
  e.stopPropagation();
  toggleAttachmentMenu();
});

document.addEventListener('click', (e) => {
  if (!ATTACH_MENU_OPEN) return;
  const target = e.target instanceof Element ? e.target : null;
  if (!target || !target.closest('[data-attach-root]')) {
    closeAttachmentMenu();
  }
});

document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape' && ATTACH_MENU_OPEN) {
    closeAttachmentMenu();
    attachToggle?.focus();
  }
});

// Webcam modal refs
const camModal  = document.getElementById('kk-camModal');
const camVideo  = document.getElementById('kk-camVideo');
const camShot   = document.getElementById('kk-camShot');
const camCancel = document.getElementById('kk-camCancel');
const camFlip   = document.getElementById('kk-camFlip');


  const imgPanel  = document.getElementById('kk-imgPanel');
  const imgView   = document.getElementById('kk-imgView');
  const imgCap    = document.getElementById('kk-imgCap');
  const imgOpen   = document.getElementById('kk-imgOpen');
  const imgClose  = document.getElementById('kk-imgClose');
  const imgActDm     = document.getElementById('kk-img-act-dm');
  const imgActReport = document.getElementById('kk-img-act-report');
  const imgActBlock  = document.getElementById('kk-img-act-block');

  const explicitModal   = document.getElementById('kk-explicitModal');
  const explicitForm    = document.getElementById('kk-explicitForm');
  const explicitCheck   = document.getElementById('kk-explicitCheck');
  const explicitName    = document.getElementById('kk-explicitName');
  const explicitCancel  = document.getElementById('kk-explicitCancel');
  const explicitConfirm = document.getElementById('kk-explicitConfirm');
  let explicitResolver  = null;

  const h = {'X-WP-Nonce': REST_NONCE};

  const API_ABSOLUTE = (() => {
    try {
      const u = new URL(API, window.location.href);
      return u.href.replace(/\/$/, '');
    } catch (_) {
      return String(API || '');
    }
  })();

  function shouldLogApiRequest(url) {
    if (!url) return false;
    try {
      const absolute = new URL(url, window.location.href).href;
      return absolute.startsWith(API_ABSOLUTE);
    } catch (_) {
      return String(url).startsWith(API);
    }
  }

  function formatApiLogTarget(url) {
    if (!url) return '';
    try {
      const absolute = new URL(url, window.location.href).href;
      if (absolute.startsWith(API_ABSOLUTE)) {
        const suffix = absolute.slice(API_ABSOLUTE.length);
        return suffix ? suffix.replace(/^\//, '') : '/';
      }
      return absolute;
    } catch (_) {
      return String(url);
    }
  }

  function logDbActivity(message, extra){
    if (extra !== undefined) {
      try {
        console.info(`KKchat: ${message}`, extra);
        return;
      } catch (_) {}
    }
    console.info(`KKchat: ${message}`);
  }

  let redirectingToLogin = false;
  async function maybeRedirectToLogin(response){
    if (!response || redirectingToLogin) return;

    const status = Number(response.status) || 0;
    if (status !== 401 && status !== 403) return;

    let code = '';
    try {
      const contentType = response.headers?.get?.('Content-Type') || '';
      if (contentType.toLowerCase().includes('application/json')) {
        const payload = await response.clone().json();
        const raw = (payload && (payload.err ?? payload.error)) ?? '';
        if (typeof raw === 'string') {
          code = raw;
        } else if (raw != null) {
          code = String(raw);
        }
      }
    } catch(_) {}

    const normalized = code.trim().toLowerCase().replace(/[\s_-]+/g, ' ');
    if (normalized !== 'not logged in') return;

    redirectingToLogin = true;
    try { clearLocalState(); } catch(_) {}
    setTimeout(() => { try { window.location.reload(); } catch(_) {} }, 50);
  }

  if (typeof window.fetch === 'function' && !window.__kk_fetch_patched) {
    const originalFetch = window.fetch.bind(window);
    window.fetch = async (...args) => {
      const [input, init] = args;
      const method = String((init && init.method) || (typeof input === 'object' && input?.method) || 'GET').toUpperCase();
      const targetUrl = typeof input === 'string' ? input : (input && input.url) || '';
      const shouldLog = shouldLogApiRequest(targetUrl);
      const logTarget = shouldLog ? formatApiLogTarget(targetUrl) : '';
      const startedAt = shouldLog ? Date.now() : 0;

      if (shouldLog) {
        const verb = method === 'GET' ? 'DB read' : 'DB write';
        logDbActivity(`${verb} starting via ${method} ${logTarget || targetUrl}`);
      }

      try {
        const response = await originalFetch(...args);
        if (shouldLog) {
          const elapsed = Date.now() - startedAt;
          logDbActivity(`DB ${method === 'GET' ? 'read' : 'write'} completed from ${method} ${logTarget || targetUrl} (status ${response?.status ?? 'n/a'} in ${elapsed}ms)`);
        }
        maybeRedirectToLogin(response).catch(()=>{});
        return response;
      } catch (err) {
        if (shouldLog) {
          const elapsed = Date.now() - startedAt;
          console.error(`KKchat: DB ${method === 'GET' ? 'read' : 'write'} failed from ${method} ${logTarget || targetUrl} after ${elapsed}ms`, err);
        }
        throw err;
      }
    };
    window.__kk_fetch_patched = true;
  }
  
  // === Seen / Read helpers ===============================================
// Server "now" used as a watermark for public room read state
let LAST_SERVER_NOW = Math.floor(Date.now() / 1000);

// Debounce: don't spam the server with identical public 'seen' calls
let _lastPublicSeenSent = 0;

/**
 * Mark a batch of DM messages as seen (by message IDs).
 * Endpoint: POST /dm/seen
 * Body:
 *   csrf_token
 *   ids[] = <messageId> (repeat for each id)
 */
// Mark the latest DM message as read for a peer
async function markDMSeen(peerId, lastId) {
  const peer = Number(peerId);
  const mid  = Number(lastId);
  if (!peer || !mid) return;
  try {
    logDbActivity(`marking DM read up to message ${mid} for peer ${peer}`);
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('dm_peer', String(peer));
    fd.append('dm_last_id', String(mid));
    await fetch(`${API}/reads/mark`, {
      method: 'POST',
      credentials: 'include',
      body: fd
    });
  } catch (e) {
    console.warn('reads/mark (DM) failed', e);
  }
}

// Advance the public watermark for a room to the latest seen message
async function markRoomSeen(slug, lastId) {
  const room = (slug || '').trim();
  const mid  = Number(lastId);
  if (!room || !mid) return;
  try {
    logDbActivity(`marking public room "${room}" read through message ${mid}`);
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('room_slug', room);
    fd.append('room_last_id', String(mid));
    if (LAST_SERVER_NOW > 0) {
      fd.append('public_since', String(LAST_SERVER_NOW));
    }
    await fetch(`${API}/reads/mark`, {
      method: 'POST',
      credentials: 'include',
      body: fd
    });
  } catch (e) {
    console.warn('reads/mark (public) failed', e);
  }
}

function isMobileLike(){
  return /Android|iPhone|iPad|iPod/i.test(navigator.userAgent) || window.matchMedia('(max-width: 900px)').matches;
}
function hasMediaDevices(){
  return !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
}


const POLL_CLIENT_ID = `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`;
const POLL_CHANNEL_NAME = 'kkchat-sync-v1';
const POLL_LEADER_KEY   = 'kkchat:poll:leader';
const POLL_SYNC_KEY     = 'kkchat:poll:last';
const POLL_POKE_KEY     = 'kkchat:poll:poke';
const POLL_HEARTBEAT_MS = 7000;
const POLL_LEADER_TTL_MS = POLL_HEARTBEAT_MS * 3;
let POLL_TIMER = null;
let POLL_BUSY = false;
let POLL_SUSPENDED = false;
let POLL_IS_LEADER = false;
let POLL_HEARTBEAT_TIMER = null;
let POLL_HOT_UNTIL = 0;
let POLL_LAST_EVENT_AT = 0;
let POLL_HIDDEN_SINCE = 0;
let BACKGROUND_POLL_TIMER = null;
let POLL_LAST_ACTIVITY_AT = Date.now();
let POLL_LAST_SCHEDULED_MS = null;
let POLL_ACTIVITY_SIGNAL_AT = 0;
let WINDOW_FOCUSED = typeof document.hasFocus === 'function' ? !!document.hasFocus() : true;
let POLL_LAST_INTERACTIVE = null;
let POLL_INFLIGHT_PROMISE = null;
let POLL_ABORT_CONTROLLER = null;
let POLL_REQUEST_SERIAL = 0;

function isInteractive(){
  return document.visibilityState === 'visible' && WINDOW_FOCUSED;
}

POLL_LAST_INTERACTIVE = isInteractive();

function stopBackgroundPolling(){
  if (BACKGROUND_POLL_TIMER) {
    clearTimeout(BACKGROUND_POLL_TIMER);
    BACKGROUND_POLL_TIMER = null;
  }
}

function handlePresenceChange(options = {}){
  const isBool = typeof options === 'boolean';
  const opts = isBool ? {} : (options || {});
  const force = isBool ? options : !!opts.force;
  const requestCold = !!opts.requestCold;

  const interactive = isInteractive();
  if (!force && interactive === POLL_LAST_INTERACTIVE) return;

  if (interactive) {
    POLL_HIDDEN_SINCE = 0;
    stopBackgroundPolling();
    resumeStream();
    noteUserActivity(true);
    if (requestCold) {
      pollActive(true).catch(()=>{});
    } else {
      pollActive().catch(()=>{});
    }
    if (currentDM != null) {
      verifyRecentDM(currentDM).catch(()=>{});
    }
    } else {
    if (!POLL_HIDDEN_SINCE) {
      POLL_HIDDEN_SINCE = Date.now();
    }
    suspendStream();
    scheduleBackgroundPoll();
  }

  POLL_LAST_INTERACTIVE = interactive;
}

function noteUserActivity(force = false){
  const now = Date.now();
  if (!force && now - POLL_ACTIVITY_SIGNAL_AT < 500) return;
  POLL_ACTIVITY_SIGNAL_AT = now;
  POLL_LAST_ACTIVITY_AT = now;

  if (!POLL_IS_LEADER || POLL_SUSPENDED) return;
  if (!isInteractive()) return;

  const hotMs = Number(POLL_SETTINGS?.hotIntervalMs) || 4000;
  if (!POLL_TIMER) return;
  if (!Number.isFinite(POLL_LAST_SCHEDULED_MS) || POLL_LAST_SCHEDULED_MS <= 0) return;
  if (POLL_LAST_SCHEDULED_MS <= hotMs * 1.25) return;

  scheduleStreamReconnect(hotMs);
}

function computeBackgroundPollDelay(){
  const settings = POLL_SETTINGS || {};
  const hiddenSince = Number(POLL_HIDDEN_SINCE) || 0;
  const hiddenThreshold = Number(settings.hiddenThresholdMs) || 90000;
  const hiddenDelay = Math.max(1000, Number(settings.hiddenDelayMs) || 30000);
  const mediumMs = Math.max(1000, Number(settings.mediumIntervalMs) || 8000);

  if (!hiddenSince) return mediumMs;

  const inactiveMs = Math.max(0, Date.now() - hiddenSince);
  if (inactiveMs >= hiddenThreshold) return hiddenDelay;
  return mediumMs;
}

function scheduleBackgroundPoll(delay){
  if (isInteractive()) return;
  if (MULTITAB_LOCKED) return;

  const wait = Math.max(1000, Number.isFinite(delay) ? delay : computeBackgroundPollDelay());
  stopBackgroundPolling();

  BACKGROUND_POLL_TIMER = setTimeout(async () => {
    BACKGROUND_POLL_TIMER = null;
    if (isInteractive()) return;
    if (MULTITAB_LOCKED) return;
    try {
      await pollActive(false, { allowSuspended: true });
    } catch (_) {
      // ignore transient failures and keep cadence
    } finally {
      scheduleBackgroundPoll();
    }
  }, wait);
}

const PREFETCH_DELAY_MS = 250;
const PREFETCH_QUEUE = [];
const PREFETCH_KEYS = new Set();
const PREFETCH_INFLIGHT = new Map();
let PREFETCH_TIMER = null;
let PREFETCH_BUSY = false;

const DM_RECENT_VERIFY_COUNT = 5;
const DM_RECENT_VERIFY_INFLIGHT = new Map();

const POLL_ETAGS = new Map();
const POLL_RETRY_HINT = new Map();

const MULTITAB_ACTIVE_KEY = 'kkchat:active-tab:v1';
const MULTITAB_HEARTBEAT_MS = 5000;
let multiTabHeartbeatTimer = null;
let multiTabOwnerId = null;
let multiTabReadyResolve;
const multiTabReady = new Promise(resolve => { multiTabReadyResolve = resolve; });
let MULTITAB_LOCKED = false;
let MULTITAB_IS_ACTIVE = false;

function signalMultiTabReady(){
  if (multiTabReadyResolve) {
    try { multiTabReadyResolve(); } catch (_) {}
    multiTabReadyResolve = null;
  }
}

function parseActiveTabRecord(raw){
  if (!raw) return null;
  try {
    const rec = typeof raw === 'string' ? JSON.parse(raw) : raw;
    if (!rec || typeof rec.id !== 'string') return null;
    return rec;
  } catch (_) {
    return null;
  }
}

function readActiveTabRecord(){
  try {
    return parseActiveTabRecord(localStorage.getItem(MULTITAB_ACTIVE_KEY));
  } catch (_) {
    return null;
  }
}

function isActiveTabStale(rec){
  if (!rec) return true;
  const ts = Number(rec.ts || 0);
  if (!Number.isFinite(ts)) return true;
  return Date.now() - ts > MULTITAB_HEARTBEAT_MS * 3;
}

function writeActiveTabRecord(){
  const rec = { id: POLL_CLIENT_ID, ts: Date.now() };
  multiTabOwnerId = rec.id;
  try { localStorage.setItem(MULTITAB_ACTIVE_KEY, JSON.stringify(rec)); } catch (_) {}
}

function startMultiTabHeartbeat(){
  stopMultiTabHeartbeat();
  writeActiveTabRecord();
  multiTabHeartbeatTimer = setInterval(() => {
    writeActiveTabRecord();
  }, MULTITAB_HEARTBEAT_MS);
}

function stopMultiTabHeartbeat(){
  if (multiTabHeartbeatTimer) {
    clearInterval(multiTabHeartbeatTimer);
    multiTabHeartbeatTimer = null;
  }
}

function setMultiTabModalMessage(text){
  if (multiTabDesc) {
    multiTabDesc.textContent = text;
  }
}

function showMultiTabModal(message){
  if (chatRoot) {
    chatRoot.setAttribute('data-multitab-locked', '1');
  }
  if (multiTabModal) {
    multiTabModal.hidden = false;
    multiTabModal.setAttribute('aria-hidden', 'false');
    multiTabModal.classList.add('is-open');
  }
  if (typeof message === 'string' && message) {
    setMultiTabModalMessage(message);
  }
  requestAnimationFrame(() => { multiTabUseHere?.focus(); });
}

function hideMultiTabModal(){
  if (chatRoot) {
    chatRoot.removeAttribute('data-multitab-locked');
  }
  if (multiTabModal) {
    multiTabModal.classList.remove('is-open');
    multiTabModal.setAttribute('aria-hidden', 'true');
    multiTabModal.hidden = true;
  }
}

function lockMultiTab(message){
  if (MULTITAB_LOCKED && message) {
    setMultiTabModalMessage(message);
  }
  if (MULTITAB_LOCKED) return;
  MULTITAB_LOCKED = true;
  MULTITAB_IS_ACTIVE = false;
  stopMultiTabHeartbeat();
  showMultiTabModal(message || 'Chatten är redan öppen i en annan flik.');
  suspendStream();
  stopKeepAlive();
  stopBackgroundPolling();
}

function unlockMultiTab(){
  if (!MULTITAB_LOCKED) return;
  MULTITAB_LOCKED = false;
  hideMultiTabModal();
  resumeStream();
  startKeepAlive();
  pollActive().catch(()=>{});
  if (!isInteractive()) {
    scheduleBackgroundPoll();
  }
}

function claimActiveTab(options = {}){
  const { forceCold = false } = options || {};
  const wasLocked = MULTITAB_LOCKED;
  MULTITAB_IS_ACTIVE = true;
  hideMultiTabModal();
  MULTITAB_LOCKED = false;
  startMultiTabHeartbeat();
  signalMultiTabReady();
  startKeepAlive();
  if (wasLocked) {
    resumeStream();
  }
  if (forceCold || wasLocked) {
    pollActive(true).catch(()=>{});
  }
}

function handleActiveTabChange(rec){
  const owner = (!rec || isActiveTabStale(rec)) ? null : rec.id;
  multiTabOwnerId = owner;

  if (owner === POLL_CLIENT_ID) {
    if (!MULTITAB_IS_ACTIVE) {
      MULTITAB_IS_ACTIVE = true;
      stopMultiTabHeartbeat();
      startMultiTabHeartbeat();
      hideMultiTabModal();
      signalMultiTabReady();
      unlockMultiTab();
    }
    return;
  }

  MULTITAB_IS_ACTIVE = false;
  if (!owner) {
    stopMultiTabHeartbeat();
    claimActiveTab({ forceCold: true });
    return;
  }

  const message = 'Chatten används nu i en annan flik. Tryck på ”Använd chatten här” för att fortsätta här.';
  lockMultiTab(message);
}

function handleActiveStorage(raw){
  handleActiveTabChange(parseActiveTabRecord(raw));
}

function initMultiTabLock(){
  const current = readActiveTabRecord();
  if (!current || isActiveTabStale(current) || current.id === POLL_CLIENT_ID) {
    claimActiveTab();
  } else {
    handleActiveTabChange(current);
    const message = 'Chatten är redan öppen i en annan flik. Tryck på ”Använd chatten här” om du vill fortsätta i den här fliken.';
    lockMultiTab(message);
  }
}

const POLL_BROADCAST = (typeof BroadcastChannel === 'function') ? new BroadcastChannel(POLL_CHANNEL_NAME) : null;
if (POLL_BROADCAST) {
  try {
    POLL_BROADCAST.addEventListener('message', (ev) => {
      handlePollMessage(ev?.data);
    });
  } catch (_) {}
}

window.addEventListener('storage', (ev) => {
  if (ev.key === POLL_LEADER_KEY) {
    handleLeaderStorage(ev.newValue);
    return;
  }
  if (ev.key === POLL_SYNC_KEY) {
    if (ev.newValue) {
      try { handlePollMessage(JSON.parse(ev.newValue)); } catch (_) {}
    }
    return;
  }
  if (ev.key === POLL_POKE_KEY) {
    if (ev.newValue) {
      try { handlePollMessage(JSON.parse(ev.newValue)); } catch (_) {}
    }
    return;
  }
  if (ev.key === MULTITAB_ACTIVE_KEY) {
    handleActiveStorage(ev.newValue);
  }
});


function desiredStreamState(){
  if (currentDM) { return { kind: 'dm', to: Number(currentDM) }; }
  if (currentRoom) { return { kind: 'room', room: currentRoom }; }
  return null;
}

function computeStreamParams(state){
  const params = new URLSearchParams();
  const last = +pubList.dataset.last || -1;
  const isCold = last < 0;
  params.set('since', String(last));
  params.set('limit', isCold ? String(FIRST_LOAD_LIMIT) : '50');
  params.set('_wpnonce', REST_NONCE);
  if (state.kind === 'dm') {
    params.set('to', String(state.to));
  } else {
    params.set('public', '1');
    params.set('room', state.room);
  }
  return params;
}

function pollContextKey(state){
  if (!state) return 'none';
  return state.kind === 'dm' ? `dm:${state.to}` : `room:${state.room}`;
}

function abortActivePoll(){
  if (!POLL_ABORT_CONTROLLER) return;
  try {
    POLL_ABORT_CONTROLLER.abort();
  } catch (_) {}
}

function stopStream(){
  if (POLL_TIMER) {
    clearTimeout(POLL_TIMER);
    POLL_TIMER = null;
  }
  POLL_LAST_SCHEDULED_MS = null;
  abortActivePoll();
}

function scheduleStreamReconnect(delay){
  const state = desiredStreamState();
  if (!state) return;
  if (!POLL_IS_LEADER || POLL_SUSPENDED) return;
const previousWait = Number(POLL_LAST_SCHEDULED_MS) || 0;
  if (POLL_TIMER) {
    clearTimeout(POLL_TIMER);
    POLL_TIMER = null;
  }

  let wait = delay;
  if (wait == null) {
    const hint = POLL_RETRY_HINT.get(pollContextKey(state));
    wait = computePollDelay(hint);
  }

  const hotMs = Number(POLL_SETTINGS?.hotIntervalMs) || 4000;
  const wasSlow = previousWait > hotMs * 1.25;
  const nowHot = Number.isFinite(wait) && wait <= hotMs;
  if (!POLL_BUSY && wasSlow && nowHot) {
    POLL_LAST_SCHEDULED_MS = null;
    performPoll().catch(()=>{});
    return;
  }


  if (!Number.isFinite(wait) || wait <= 0) {
        wait = hotMs;
  }

  const jitter = 0.8 + Math.random() * 0.4;
  wait = Math.max(500, Math.round(wait * jitter));
  POLL_LAST_SCHEDULED_MS = wait;

  POLL_TIMER = setTimeout(() => {
    POLL_TIMER = null;
    POLL_LAST_SCHEDULED_MS = null;
    performPoll();
  }, wait);
}

function enqueuePrefetch(kind, value){
  if (value == null) return;
  const key = `${kind}:${value}`;
  if (PREFETCH_KEYS.has(key)) return;
  PREFETCH_KEYS.add(key);
  PREFETCH_QUEUE.push({ kind, value, key });
  schedulePrefetchTick();
}

function queuePrefetchRoom(slug){
  const room = String(slug || '').trim();
  if (!room) return;
  enqueuePrefetch('room', room);
}

function queuePrefetchDM(userId){
  const id = Number(userId);
  if (!Number.isFinite(id) || id <= 0) return;
  enqueuePrefetch('dm', id);
}

function schedulePrefetchTick(){
  if (PREFETCH_BUSY) return;
  if (PREFETCH_TIMER) return;
  if (!PREFETCH_QUEUE.length) return;
  PREFETCH_TIMER = setTimeout(runPrefetchTick, PREFETCH_DELAY_MS);
}

async function runPrefetchTick(){
  if (PREFETCH_BUSY) return;
  PREFETCH_TIMER = null;
  const next = PREFETCH_QUEUE.shift();
  if (!next) return;

  PREFETCH_BUSY = true;
  let promise = null;
  try {
    if (next.kind === 'room') {
      promise = prefetchRoom(next.value);
    } else if (next.kind === 'dm') {
      promise = prefetchDM(next.value);
    }

    if (promise && typeof promise.then === 'function') {
      PREFETCH_INFLIGHT.set(next.key, promise);
      await promise.catch(()=>{});
    }
  } catch (_) {} finally {
    PREFETCH_INFLIGHT.delete(next.key);
    PREFETCH_KEYS.delete(next.key);
    PREFETCH_BUSY = false;

    if (PREFETCH_QUEUE.length) {
      schedulePrefetchTick();
    }
  }
}

async function ensurePrefetched(kind, value){
  const key = `${kind}:${value}`;

  const inflight = PREFETCH_INFLIGHT.get(key);
  if (inflight) {
    try { await inflight; } catch (_) {}
    return true;
  }

  let wasQueued = false;
  for (let i = PREFETCH_QUEUE.length - 1; i >= 0; i--) {
    const item = PREFETCH_QUEUE[i];
    if (item && item.key === key) {
      PREFETCH_QUEUE.splice(i, 1);
      wasQueued = true;
      break;
    }
  }

  if (!wasQueued && !PREFETCH_KEYS.has(key)) {
    return false;
  }

  PREFETCH_KEYS.delete(key);

  try {
    const promise = kind === 'room' ? prefetchRoom(value) : prefetchDM(value);
    if (promise && typeof promise.then === 'function') {
      PREFETCH_INFLIGHT.set(key, promise);
      await promise.catch(()=>{});
    }
  } catch (_) {
    // swallow
  } finally {
    PREFETCH_INFLIGHT.delete(key);
  }

  return true;
}

function hasPendingPrefetch(key){
  if (!key) return false;
  if (PREFETCH_INFLIGHT.has(key)) return true;
  if (PREFETCH_KEYS.has(key)) return true;
  for (let i = 0; i < PREFETCH_QUEUE.length; i++) {
    if (PREFETCH_QUEUE[i]?.key === key) return true;
  }
  return false;
}

async function ensurePrefetchedRoom(slug){
  const room = String(slug || '').trim();
  if (!room) return false;
  return ensurePrefetched('room', room);
}

async function ensurePrefetchedDM(userId){
  const id = Number(userId);
  if (!Number.isFinite(id) || id <= 0) return false;
  return ensurePrefetched('dm', id);
}

function openStream(forceCold = false){
  if (POLL_SUSPENDED || MULTITAB_LOCKED) return;
  const state = desiredStreamState();
  if (!state) return;

  ensureLeader(false);
  if (!POLL_IS_LEADER) { return; }

  if (forceCold) {
    POLL_ETAGS.delete(pollContextKey(state));
  }

  stopStream();
  performPoll(forceCold).catch(()=>{});
}

function suspendStream(){
  POLL_SUSPENDED = true;
  stopStream();
}

function resumeStream(){
  if (!POLL_SUSPENDED) return;
  if (MULTITAB_LOCKED) return;
  POLL_SUSPENDED = false;
}

function restartStream(){
  stopStream();
  if (!POLL_SUSPENDED) {
    openStream(true);
  }
}

function readLeaderRecord(){
  try {
    const raw = localStorage.getItem(POLL_LEADER_KEY);
    if (!raw) return null;
    const rec = JSON.parse(raw);
    if (!rec || typeof rec.id !== 'string') return null;
    return rec;
  } catch (_) {
    return null;
  }
}

function leaderExpired(rec){
  if (!rec) return true;
  const ts = Number(rec.ts || 0);
  if (!Number.isFinite(ts)) return true;
  return Date.now() - ts > POLL_LEADER_TTL_MS;
}

function becomeLeader(){
  POLL_IS_LEADER = true;
  try {
    localStorage.setItem(POLL_LEADER_KEY, JSON.stringify({ id: POLL_CLIENT_ID, ts: Date.now() }));
  } catch (_) {}
  startLeaderHeartbeat();
  scheduleStreamReconnect(0);
}

function ensureLeader(force = false){
  const rec = readLeaderRecord();
  if (force || !rec || leaderExpired(rec) || rec.id === POLL_CLIENT_ID) {
    becomeLeader();
    return true;
  }

  POLL_IS_LEADER = rec.id === POLL_CLIENT_ID;
  if (POLL_IS_LEADER) {
    startLeaderHeartbeat();
  } else {
    stopLeaderHeartbeat();
    stopStream();
  }
  return POLL_IS_LEADER;
}

function startLeaderHeartbeat(){
  if (POLL_HEARTBEAT_TIMER) return;
  POLL_HEARTBEAT_TIMER = setInterval(() => {
    if (!POLL_IS_LEADER) {
      stopLeaderHeartbeat();
      return;
    }
    try {
      localStorage.setItem(POLL_LEADER_KEY, JSON.stringify({ id: POLL_CLIENT_ID, ts: Date.now() }));
    } catch (_) {}
  }, POLL_HEARTBEAT_MS);
}

function stopLeaderHeartbeat(){
  if (POLL_HEARTBEAT_TIMER) {
    clearInterval(POLL_HEARTBEAT_TIMER);
    POLL_HEARTBEAT_TIMER = null;
  }
}

function handleLeaderStorage(value){
  let rec = null;
  if (value) {
    try { rec = JSON.parse(value); } catch (_) { rec = null; }
  }

  if (!rec || leaderExpired(rec)) {
    ensureLeader(false);
    return;
  }

  const isSelf = rec.id === POLL_CLIENT_ID;
  POLL_IS_LEADER = isSelf;
  if (isSelf) {
    startLeaderHeartbeat();
    return;
  }

  stopLeaderHeartbeat();
  stopStream();
}

function computePollDelay(hintMs){
  const now = Date.now();
  const settings = POLL_SETTINGS || {};
  const hiddenThreshold = Number(settings.hiddenThresholdMs) || 90000;
  const hiddenDelay = Number(settings.hiddenDelayMs) || 30000;
  const hotMs = Number(settings.hotIntervalMs) || 4000;
  const mediumMs = Number(settings.mediumIntervalMs) || 8000;
  const slowMs = Number(settings.slowIntervalMs) || 16000;
  const mediumAfterMs = Number(settings.mediumAfterMs) || 180000;
  const slowAfterMs = Math.max(mediumAfterMs, Number(settings.slowAfterMs) || 300000);

  const hiddenSince = Number(POLL_HIDDEN_SINCE) || 0;
  const inactive = !isInteractive();
  const hiddenFor = inactive && hiddenSince
    ? now - hiddenSince
    : 0;

  let base;
  if (inactive && hiddenFor >= hiddenThreshold) {
    base = hiddenDelay;
  } else {
    const activitySince = now - (Number(POLL_LAST_ACTIVITY_AT) || 0);
    if (now < POLL_HOT_UNTIL) {
      base = hotMs;
    } else if (!Number.isFinite(activitySince) || activitySince <= mediumAfterMs) {
      base = hotMs;
    } else if (activitySince <= slowAfterMs) {
      base = mediumMs;
    } else {
      base = slowMs;
    }
  }

  const conn = navigator.connection?.effectiveType || '';
  const extra2g = Number(settings.extra2gMs) || 20000;
  const extra3g = Number(settings.extra3gMs) || 10000;
  if (/slow-2g|2g/i.test(conn)) {
    base += extra2g;
  } else if (/3g/i.test(conn)) {
    base += extra3g;
  }

  if (hintMs != null && Number.isFinite(hintMs)) {
    base = Math.max(base, hintMs);
  }

  if (!Number.isFinite(base) || base <= 0) {
    base = hotMs;
  }

  return base;
}

function parseRetryAfter(header){
  if (!header) return null;
  const num = Number(header);
  if (Number.isFinite(num) && num >= 0) return num;
  return null;
}

function broadcastSync(state, payload, meta){
  const message = {
    type: 'sync',
    from: POLL_CLIENT_ID,
    key: pollContextKey(state),
    state,
    data: payload,
    meta: meta || {},
    ts: Date.now()
  };

  if (POLL_BROADCAST) {
    try { POLL_BROADCAST.postMessage(message); } catch (_) {}
  }

  try {
    localStorage.setItem(POLL_SYNC_KEY, JSON.stringify(message));
    localStorage.removeItem(POLL_SYNC_KEY);
  } catch (_) {}
}


function requestLeaderSync(forceCold = false){
  const state = desiredStreamState();
  if (!state) return;
  const message = {
    type: 'poke',
    from: POLL_CLIENT_ID,
    key: pollContextKey(state),
    forceCold: !!forceCold,
    ts: Date.now()
  };
  if (POLL_BROADCAST) {
    try { POLL_BROADCAST.postMessage(message); } catch (_) {}
  }
  try {
    localStorage.setItem(POLL_POKE_KEY, JSON.stringify(message));
    localStorage.removeItem(POLL_POKE_KEY);
  } catch (_) {}
}

function handlePollMessage(msg){
  if (!msg || typeof msg !== 'object') return;
  if (msg.from === POLL_CLIENT_ID) return;

  if (msg.type === 'sync') {
    const state = desiredStreamState();
    if (!state) return;
    if (msg.key && msg.key !== pollContextKey(state)) return;
    const data = msg.data;
    if (!data) return;
    if (data && Number.isFinite(+data.now)) {
      LAST_SERVER_NOW = +data.now;
    } else {
      LAST_SERVER_NOW = Math.floor(Date.now() / 1000);
    }
    const ctx = msg.state || state;
    handleStreamSync(data, ctx);
    updateListLastFromPayload(data, ctx);
    return;
  }

  if (msg.type === 'poke') {
    if (!POLL_IS_LEADER) return;
    const state = desiredStreamState();
    if (!state) return;
    if (msg.key && msg.key !== pollContextKey(state)) return;
    const forceCold = !!msg.forceCold;
    if (forceCold) {
      POLL_ETAGS.delete(pollContextKey(state));
    }
    stopStream();
    performPoll(forceCold).catch(()=>{});
  }
}

async function performPoll(forceCold = false, options = {}){
  const { allowSuspended = false } = options || {};
  const previousPromise = POLL_INFLIGHT_PROMISE;
  if (previousPromise) {
    abortActivePoll();
    try { await previousPromise; } catch (_) {}
  }

  if (POLL_BUSY || (POLL_SUSPENDED && !allowSuspended)) return;

  const state = desiredStreamState();
  if (!state) {
    stopStream();
    return;
  }

  if (!POLL_IS_LEADER) {
    return;
  }

  const key = pollContextKey(state);
  const params = computeStreamParams(state);
  if (forceCold) {
    params.set('since', '-1');
    params.set('limit', String(FIRST_LOAD_LIMIT));
    POLL_ETAGS.delete(key);
  }

  const headers = new Headers(h);
  headers.set('Accept', 'application/json');
  headers.set('Cache-Control', 'no-cache');

  const etag = POLL_ETAGS.get(key);
  if (etag) {
    headers.set('If-None-Match', etag);
  }

  const url = `${API}/sync?${params.toString()}`;
  logDbActivity(`starting sync poll${forceCold ? ' (force cold start)' : ''} for key ${key}`);

  abortActivePoll();
  const controller = new AbortController();
  POLL_ABORT_CONTROLLER = controller;
  const requestSerial = ++POLL_REQUEST_SERIAL;

  const pollPromise = (async () => {
    POLL_BUSY = true;
    try {
      const resp = await fetch(url, { credentials: 'include', headers, signal: controller.signal });
      const retryHeader = parseRetryAfter(resp.headers.get('Retry-After'));

      if (requestSerial !== POLL_REQUEST_SERIAL) {
        return;
      }

      if (resp.status === 204 || resp.status === 304) {
        if (retryHeader != null) {
          POLL_RETRY_HINT.set(key, retryHeader * 1000);
        }
        logDbActivity(`sync poll returned no changes (${resp.status})`);
        scheduleStreamReconnect(retryHeader != null ? retryHeader * 1000 : undefined);
        return;
      }

      if (resp.status === 429) {
        if (retryHeader != null) {
          POLL_RETRY_HINT.set(key, retryHeader * 1000);
          scheduleStreamReconnect(retryHeader * 1000);
        } else {
          scheduleStreamReconnect(15000);
        }
        logDbActivity('sync poll throttled (HTTP 429)');
        return;
      }

      if (!resp.ok) {
        throw new Error(`sync ${resp.status}`);
      }

      const payload = await resp.json();
      if (requestSerial !== POLL_REQUEST_SERIAL) {
        return;
      }

      const bodyRetry = Number(payload?.retryAfter || payload?.retry_after || 0);
      const retryMs = bodyRetry > 0 ? bodyRetry * 1000 : (retryHeader != null ? retryHeader * 1000 : null);
      if (retryMs != null) {
        POLL_RETRY_HINT.set(key, retryMs);
      }

      const headerEtag = resp.headers.get('ETag');
      if (headerEtag) {
        POLL_ETAGS.set(key, headerEtag);
      }

      if (payload && Number.isFinite(+payload.now)) {
        LAST_SERVER_NOW = +payload.now;
      } else {
        LAST_SERVER_NOW = Math.floor(Date.now() / 1000);
      }

      handleStreamSync(payload, state);
      updateListLastFromPayload(payload, state);

      let hadMessages = false;
      if (Array.isArray(payload?.events)) {
        hadMessages = payload.events.some(ev => Array.isArray(ev?.messages) && ev.messages.length > 0);
      } else if (Array.isArray(payload?.messages) && payload.messages.length > 0) {
        hadMessages = true;
      }

      if (hadMessages) {
        POLL_LAST_EVENT_AT = Date.now();
        POLL_HOT_UNTIL = POLL_LAST_EVENT_AT + 120000;
      }

      const msgCount = Array.isArray(payload?.messages) ? payload.messages.length : 0;
      logDbActivity(`sync poll completed with status ${resp.status}${msgCount ? ` and ${msgCount} messages` : ''}`);
      broadcastSync(state, payload, { retryAfterMs: retryMs ?? undefined });
    } catch (err) {
      if (err?.name === 'AbortError') {
        return;
      }
      if (requestSerial !== POLL_REQUEST_SERIAL) {
        return;
      }
      console.warn('KKchat: sync poll failed', err);
      const fallback = Math.max(8000, (POLL_RETRY_HINT.get(key) || 8000) * 1.5);
      POLL_RETRY_HINT.set(key, fallback);
    } finally {
      if (POLL_ABORT_CONTROLLER === controller) {
        POLL_ABORT_CONTROLLER = null;
      }
      POLL_BUSY = false;
      if (requestSerial === POLL_REQUEST_SERIAL) {
        scheduleStreamReconnect();
      }
    }
  })();

  const wrappedPromise = pollPromise.finally(() => {
    if (POLL_INFLIGHT_PROMISE === wrappedPromise) {
      POLL_INFLIGHT_PROMISE = null;
    }
  });

  POLL_INFLIGHT_PROMISE = wrappedPromise;
  await wrappedPromise;
}

// --- SOUND MUTE WINDOW -----------------------------------------
function soundsMuted(){
  return (window.__kk_sound_mute_until || 0) > Date.now();
}
function muteFor(ms = 1200){
  window.__kk_sound_mute_until = Date.now() + ms;
}

function playNotifOnce() {
  try {
    if (soundsMuted()) return;                 // ⛔ muted due to a recent tab/DM switch
    const ding = document.getElementById('kk-notifSound');
    if (!ding) return;
    const now = Date.now();
    window.__kk_last_notif_at = window.__kk_last_notif_at || 0;
    if (now - window.__kk_last_notif_at < 1200) return; // throttle
    ding.currentTime = 0;
    ding.play()?.catch(()=>{});
    window.__kk_last_notif_at = now;
  } catch (_) {}
}



  const AUTO_KEY = 'kk_autoscroll';
  let AUTO_SCROLL = (localStorage.getItem(AUTO_KEY) ?? '1') === '1';

    const root = document.getElementById('kkchat-root');

    const BLUR_KEY = 'kk_blur_images';
    let IMG_BLUR = (localStorage.getItem(BLUR_KEY) || '1') === '1';
    let SETTINGS_OPEN = false;
    let ADMIN_MENU_OPEN = false;

    function resetInlineImageBlur(){
      document.querySelectorAll('img.imgmsg').forEach(img => img.removeAttribute('data-unblurred'));
    }

    function applyBlurClass(){ root?.classList.toggle('nsfw-blur', IMG_BLUR); }
    function setImageBlur(on){
      SETTINGS_OPEN = false;
      IMG_BLUR = !!on;
      localStorage.setItem(BLUR_KEY, IMG_BLUR ? '1' : '0');
      if (IMG_BLUR) resetInlineImageBlur();
      applyBlurClass();
      renderRoomTabs();
    }
    if (IMG_BLUR) resetInlineImageBlur();
    applyBlurClass();

  const ROOM_CACHE = new Map();          
  const FIRST_LOAD_LIMIT = 20;          
  const AUTO_OPEN_DM_ON_NEW = false; 
  const JOIN_KEY = 'kk_joined_rooms_v1';

  function clearLocalState(){
      try{
        localStorage.removeItem('kk_active_dms_v1');
        localStorage.removeItem('kk_joined_rooms_v1');
        localStorage.removeItem('kk_autoscroll');
      }catch(_){}
    }

async function doLogout(){
  if (!confirm('Vill du logga ut?')) return;

  const fd = new FormData();
  fd.append('csrf_token', CSRF);

  let ok = false, err = '';

  try{
    const r = await fetch(API + '/logout', {
      method: 'POST',
      body: fd,
      credentials: 'include',
      headers: h
    });
    if (r.ok) {
      const js = await r.json().catch(()=>({}));
      ok = (js?.ok !== false); 
      err = js?.err || '';
    } else if (r.status === 403) {

      const r2 = await fetch(API + '/logout', {
        method: 'POST',
        body: fd,
        credentials: 'include'
      });
      const js2 = await r2.json().catch(()=>({}));
      ok = r2.ok && (js2?.ok !== false);
      err = js2?.err || '';
    }
  }catch(_){

    try{
      const r3 = await fetch(API + '/logout', {
        method: 'POST',
        body: fd,
        credentials: 'include'
      });
      const js3 = await r3.json().catch(()=>({}));
      ok = r3.ok && (js3?.ok !== false);
      err = js3?.err || '';
    }catch(_){}
  }

  clearLocalState();

  if (!ok && err) {
    alert('Kunde inte logga ut: ' + err);
  }

  const base = location.pathname + location.search.replace(/[?&]kkts=\d+/, '');
  location.replace(base + (base.includes('?') ? '&' : '?') + 'kkts=' + Date.now());
}

  function loadJoined(){
    try{ const raw = localStorage.getItem(JOIN_KEY)||'[]'; const arr = JSON.parse(raw); return new Set(Array.isArray(arr)?arr:[]); }catch(_){ return new Set(); }
  }
  function saveJoined(set){
    try{ localStorage.setItem(JOIN_KEY, JSON.stringify([...set])); }catch(_){}
  }
  let JOINED = loadJoined();

    // --- Local (browser-only) mute state ---
    const MUTE_ROOMS_KEY      = 'kk_muted_rooms_v1';
    const MUTE_DMS_KEY        = 'kk_muted_dms_v1';
    const MUTE_NEW_CHATS_KEY  = 'kk_mute_new_chats_v1';
    
    function loadSet(key){
      try{ const raw = localStorage.getItem(key)||'[]';
           const arr = JSON.parse(raw);
           return new Set(Array.isArray(arr)?arr:[]); }catch(_){ return new Set(); }
    }
    function saveSet(key, set){
      try{ localStorage.setItem(key, JSON.stringify([...set])); }catch(_){}
    }
    
    let MUTED_ROOMS     = loadSet(MUTE_ROOMS_KEY);
    let MUTED_DMS       = loadSet(MUTE_DMS_KEY);
    let MUTE_NEW_CHATS  = (localStorage.getItem(MUTE_NEW_CHATS_KEY) || '0') === '1';

    function applyMutePreferenceToChat(kind, identifier){
      if (kind === 'room') {
        const slug = String(identifier || '').trim();
        if (!slug) return;
        if (MUTE_NEW_CHATS) {
          MUTED_ROOMS.add(slug);
        } else {
          MUTED_ROOMS.delete(slug);
        }
        return;
      }

      if (kind === 'dm') {
        const id = Number(identifier);
        if (!Number.isFinite(id)) return;
        if (MUTE_NEW_CHATS) {
          MUTED_DMS.add(id);
        } else {
          MUTED_DMS.delete(id);
        }
      }
    }

    function setMuteNewChats(on){
      SETTINGS_OPEN = false;
      const next = !!on;
      if (MUTE_NEW_CHATS === next) return;
      MUTE_NEW_CHATS = next;
      try {
        localStorage.setItem(MUTE_NEW_CHATS_KEY, MUTE_NEW_CHATS ? '1' : '0');
      } catch (_) {}

      for (const slug of JOINED) {
        applyMutePreferenceToChat('room', slug);
      }
      for (const id of ACTIVE_DMS) {
        applyMutePreferenceToChat('dm', id);
      }

      saveSet(MUTE_ROOMS_KEY, MUTED_ROOMS);
      saveSet(MUTE_DMS_KEY, MUTED_DMS);
      renderRoomTabs();
    }
    
    function isRoomMuted(slug){ return !!slug && MUTED_ROOMS.has(String(slug)); }
    function isDmMuted(id){ return Number.isFinite(+id) && MUTED_DMS.has(+id); }
    
    function toggleRoomMute(slug){
      SETTINGS_OPEN = false;
      if (!slug) return;
      if (MUTED_ROOMS.has(String(slug))) MUTED_ROOMS.delete(String(slug));
      else MUTED_ROOMS.add(String(slug));
      saveSet(MUTE_ROOMS_KEY, MUTED_ROOMS);
      renderRoomTabs();
    }

    function toggleDmMute(id){
      const n = +id; if (!Number.isFinite(n)) return;
      SETTINGS_OPEN = false;
      if (MUTED_DMS.has(n)) MUTED_DMS.delete(n); else MUTED_DMS.add(n);
      saveSet(MUTE_DMS_KEY, MUTED_DMS);
      renderRoomTabs();
    }

    function toggleActiveChatMute(){
      if (currentDM != null) { toggleDmMute(currentDM); return; }
      if (currentRoom) toggleRoomMute(currentRoom);
    }

    function allChatsMuted(){
      let anyRoomUnmuted = false;
      for (const slug of JOINED) {
        if (!MUTED_ROOMS.has(String(slug))) { anyRoomUnmuted = true; break; }
      }
      if (!anyRoomUnmuted) {
        for (const id of ACTIVE_DMS) {
          const n = +id;
          if (Number.isFinite(n) && !MUTED_DMS.has(n)) { anyRoomUnmuted = true; break; }
        }
      }
      return !anyRoomUnmuted;
    }

    function setAllMute(on){
      const shouldMute = !!on;
      if (shouldMute) {
        for (const slug of JOINED) {
          MUTED_ROOMS.add(String(slug));
        }
        for (const id of ACTIVE_DMS) {
          const n = +id; if (Number.isFinite(n)) MUTED_DMS.add(n);
        }
      } else {
        for (const slug of JOINED) {
          MUTED_ROOMS.delete(String(slug));
        }
        for (const id of ACTIVE_DMS) {
          const n = +id; if (Number.isFinite(n)) MUTED_DMS.delete(n);
        }
      }
      saveSet(MUTE_ROOMS_KEY, MUTED_ROOMS);
      saveSet(MUTE_DMS_KEY, MUTED_DMS);
      renderRoomTabs();
    }
    
    // Current view mute helper (used by sound logic)
    function currentChatMuted(){
      return (currentDM != null) ? isDmMuted(currentDM) : isRoomMuted(currentRoom);
    }
    
    // Small helpers to render the icon HTML
    function roomMuteHTML(slug){
      const muted = isRoomMuted(slug);
      const title = muted ? 'Slå på ljud för rummet' : 'Tysta rummet';
    return `<span class="tab-mute" data-mute-room="${escAttr(slug)}" title="${title}" aria-label="${title}" aria-pressed="${muted?'true':'false'}" tabindex="0" role="button">${muted ? iconMarkup('notifications_off') : iconMarkup('notifications')}</span>`;
    }
    function dmMuteHTML(id){
      const muted = isDmMuted(id);
      const title = muted ? 'Slå på ljud för DM' : 'Tysta DM';
    return `<span class="tab-mute" data-mute-dm="${id}" title="${title}" aria-label="${title}" aria-pressed="${muted?'true':'false'}" tabindex="0" role="button">${muted ? iconMarkup('notifications_off') : iconMarkup('notifications')}</span>`;
    }


  const DM_KEY       = 'kk_active_dms_v1';
  const DM_SEEN_KEY  = 'kk_seen_dms_v1';
  function loadDMActive(){
    try{ const raw = localStorage.getItem(DM_KEY)||'[]'; const arr = JSON.parse(raw); return new Set(Array.isArray(arr)?arr:[]); }catch(_){ return new Set(); }
  }
  function saveDMActive(set){
    try{ localStorage.setItem(DM_KEY, JSON.stringify([...set])); }catch(_){}
  }
  function loadDMSeen(){
    try{ const raw = localStorage.getItem(DM_SEEN_KEY)||'[]'; const arr = JSON.parse(raw); return new Set(Array.isArray(arr)?arr:[]); }catch(_){ return new Set(); }
  }
  function saveDMSeen(set){
    try{ localStorage.setItem(DM_SEEN_KEY, JSON.stringify([...set])); }catch(_){}
  }
  let ACTIVE_DMS = loadDMActive();
  let SEEN_DMS   = loadDMSeen();
  let INITIAL_DM_PREFETCH_DONE = false;

  let BLOCKED = new Set();
  function isBlocked(id){ return BLOCKED.has(Number(id)); }
  
function parseBannerPayload(content){
  if (typeof content !== 'string') return { text: '' };
  try {
    const data = JSON.parse(content);
    if (data && typeof data === 'object') return data;
  } catch(_){ }
  return { text: String(content) };
}

function hexToRgba(hex, alpha){
  const m = /^#?([0-9a-fA-F]{6})$/.exec(String(hex||'').trim());
  if (!m) return '';
  const int = parseInt(m[1], 16);
  const r = (int >> 16) & 255;
  const g = (int >> 8) & 255;
  const b = int & 255;
  return `rgba(${r}, ${g}, ${b}, ${alpha})`;
}

function normalizeHex(hex){
  const m = /^#?([0-9a-fA-F]{6})$/.exec(String(hex||''));
  return m ? `#${m[1].toLowerCase()}` : '';
}

function shadeHex(hex, factor){
  const m = /^#?([0-9a-fA-F]{6})$/.exec(String(hex||''));
  if (!m) return '';
  const int = parseInt(m[1], 16);
  const r = (int >> 16) & 255;
  const g = (int >> 8) & 255;
  const b = int & 255;
  const shade = (channel) => {
    const target = factor > 0 ? 255 : 0;
    const delta = (target - channel) * Math.abs(factor);
    return Math.max(0, Math.min(255, Math.round(channel + (factor > 0 ? delta : -delta))));
  };
  const toHex = (n) => n.toString(16).padStart(2, '0');
  return `#${toHex(shade(r))}${toHex(shade(g))}${toHex(shade(b))}`;
}

function bannerAutolink(text){
  const safe = esc(String(text||''));
  return safe.replace(/(https?:\/\/[^\s<]+)/gi, m => {
    const url = escAttr(m);
    return `<a href="${url}" target="_blank" rel="noopener">${esc(m)}</a>`;
  });
}

function normalizeBannerHTML(payload){
  const rawHtml = typeof payload.html === 'string' ? payload.html : '';
  const tmp = document.createElement('div');
  tmp.innerHTML = rawHtml;

  const anchors = tmp.querySelectorAll('a');
  const hasAnchors = anchors.length > 0;

  if (!hasAnchors) {
    // If the stored HTML only has basic breaks, auto-link the textual content so custom
    // anchor markup like <a href="…">Label</a> or pasted URLs become clickable.
    const text = rawHtml ? tmp.textContent || '' : (payload.text || '');
    const autolinked = bannerAutolink(text);
    tmp.innerHTML = autolinked.replace(/\n/g, '<br>');
  }

  if (payload.link_url && tmp.querySelectorAll('a').length === 0) {
    const href = escAttr(payload.link_url);
    tmp.innerHTML = `<a href="${href}" target="_blank" rel="noopener">${tmp.innerHTML || esc(payload.link_url)}</a>`;
  }

  tmp.querySelectorAll('a').forEach(a => {
    a.target = '_blank';
    const rel = new Set((a.rel || '').split(/\s+/).filter(Boolean));
    rel.add('noopener');
    a.rel = Array.from(rel).join(' ');
  });
  return tmp.innerHTML;
}

function bannerStyleAttr(payload){
  const color = normalizeHex(typeof payload.bg_color === 'string' ? payload.bg_color.trim() : '');
  if (!color) return '';
  const darker = shadeHex(color, -0.2) || color;
  const border = hexToRgba(darker, 0.75) || darker;
  const shadow = hexToRgba(color, 0.35) || color;
  const bg = `linear-gradient(135deg, ${color} 0%, ${darker} 100%)`;
  return ` style="--banner-bg:${escAttr(bg)};--banner-border:${escAttr(border)};--banner-shadow:${escAttr(shadow)}"`;
}

function bannerImageHTML(payload){
  if (!payload.image_url) return '';
  const target = escAttr(payload.link_url || payload.image_url);
  const src = escAttr(payload.image_url);
  return `<div class="banner-bubble__image"><a href="${target}" target="_blank" rel="noopener"><img class="banner-media" src="${src}" alt="Bannerbild" loading="lazy" decoding="async"></a></div>`;
}

function msgToHTML(m){
  const mid = Number(m.id);
  if (!Number.isFinite(mid)) return '';

  rememberMessageMeta(m);

  const kind = String(m.kind || 'chat');
  const sid = Number(m.sender_id||0);
  const who = m.sender_name || 'Okänd';
  const when = new Date((m.time||0)*1000).toLocaleTimeString('sv-SE',{hour:'2-digit',minute:'2-digit'});
  const roleClass = isAdminById?.(sid) ? ' admin' : '';
  const isExplicit = !!m.is_explicit;
  rememberName(sid, m.sender_name);
  const gender = genderById(sid);
  const metaHTML = `<div class="bubble-meta small">${genderIconMarkup(gender)}<span class="bubble-meta-text">${who===ME_NM?'':esc(who)}<br>${esc(when)}</span></div>`;

  const attrs = [
    `class=\"item ${sid===ME_ID?'me':'them'}${roleClass}\"`,
    `data-id=\"${mid}\"`,
    `data-sid=\"${sid}\"`,
    `data-sname=\"${escAttr(who)}\"`,
    `data-kind=\"${escAttr(kind)}\"`
  ];
  if (kind === 'image') {
    attrs.push(`data-explicit=\"${isExplicit ? '1' : '0'}\"`);
  }

  if (kind === 'banner'){
    const payload = parseBannerPayload(m.content);
    const textHTML = normalizeBannerHTML(payload);
    const imageHTML = bannerImageHTML(payload);
    const styleAttr = bannerStyleAttr(payload);
    const body = payload.text || (typeof payload.html === 'string' ? payload.html : '');
    return `<li ${attrs.join(' ')} data-body="${escAttr(body)}"><div class="banner-bubble"${styleAttr}>${textHTML}${imageHTML}</div></li>`;
  }

  const canReply = sid > 0 && sid !== Number(ME_ID);
  const replyTargetId = Number(m.reply_to_id || 0) > 0 ? Number(m.reply_to_id) : null;
  let replySenderName = m.reply_to_sender_name || null;
  let replyExcerpt = m.reply_to_excerpt || null;
  if (replyTargetId) {
    const preview = messagePreviewById(replyTargetId);
    if (preview) {
      if (!replySenderName && preview.sender_name) replySenderName = preview.sender_name;
      if ((!replyExcerpt || replyExcerpt === '') && preview.excerpt) replyExcerpt = preview.excerpt;
    }
  }

  const replyPreviewHTML = replyTargetId ? replyPreviewMarkup(replyTargetId, replySenderName, replyExcerpt) : '';
  const replyButtonHTML = canReply ? `<button type="button" class="bubble-reply-btn" data-reply-source="${mid}" aria-label="Svara"><span class="${MATERIAL_ICON_CLASS}" aria-hidden="true">reply</span></button>` : '';

  if (kind === 'image'){
    const u = String(m.content||'').trim();
    const alt = `Bild från ${sid === ME_ID ? 'dig' : who}`;
    const badge = isExplicit ? '<span class="imgbadge" aria-label="Markerad som XXX">XXX</span>' : '';
    return `<li ${attrs.join(' ')} data-body="${escAttr('[Bild]')}"${replyTargetId ? ` data-reply-id="${replyTargetId}" data-reply-name="${escAttr(replySenderName || '')}" data-reply-excerpt="${escAttr(replyExcerpt || '')}"` : ''}>
      <div class="bubble img${isExplicit ? ' is-explicit' : ''}">${replyButtonHTML}${replyPreviewHTML}${badge}<img class="imgmsg" src="${escAttr(u)}" data-explicit="${isExplicit ? '1' : '0'}" alt="${escAttr(alt)}" loading="lazy" decoding="async"></div>
      ${metaHTML}
    </li>`;
  }

  const txt = String(m.content||'');
  const isMention = textMentionsName?.(txt, ME_NM) && sid !== ME_ID;
  const mentionClass = isMention ? ' mention' : '';
  const mentionAttr = isMention ? ' data-mention="1"' : '';

  return `<li ${attrs.join(' ')} data-body="${escAttr(txt)}"${replyTargetId ? ` data-reply-id="${replyTargetId}" data-reply-name="${escAttr(replySenderName || '')}" data-reply-excerpt="${escAttr(replyExcerpt || '')}"` : ''}${mentionAttr}>
    <div class="bubble${mentionClass}">${replyButtonHTML}${replyPreviewHTML}<div class="bubble-text">${esc(txt)}</div></div>
    ${metaHTML}
  </li>`;
}

function msgsToHTML(items, cap=180){
  // keep only tail to avoid huge strings
  const arr = items.slice(-cap);
  return arr.map(msgToHTML).join('');
}



function writeCache(key, patch){
  if (!key) return;
  const prev = ROOM_CACHE.get(key) || {};
  ROOM_CACHE.set(key, { ...prev, ...patch });
}

async function prefetchRoom(slug){
  try{
    const key    = cacheKeyForRoom(slug);
    const cached = ROOM_CACHE.get(key);
    const since  = cached ? (Number(cached.last) || -1) : -1;

    const params = new URLSearchParams({
      public:'1', room: slug, since: String(since), limit:'30'
    });
    const items = await fetchJSON(`${API}/fetch?${params}`);

    if (!Array.isArray(items) || items.length === 0) return;

    let maxId = cached?.last || -1;
    maxId = items.reduce((mx, m) => Math.max(mx, Number(m.id)||-1), maxId);

    const addHTML  = msgsToHTML(items.filter(m => Number(m.id) > since));
    const combined = clampHTML((cached?.html || '') + addHTML);   // ⬅️ clamp
    writeCache(key, { last: maxId, html: combined, isFull: false });
  }catch(_){}
}

async function prefetchDM(userId){
  try{
    const key    = cacheKeyForDM(userId);
    const cached = ROOM_CACHE.get(key);
    const since  = cached ? (Number(cached.last) || -1) : -1;

    const params = new URLSearchParams({
      to: String(userId), since: String(since), limit:'10'
    });
    const items = await fetchJSON(`${API}/fetch?${params}`);

    if (!Array.isArray(items) || items.length === 0) return;

    let maxId = cached?.last || -1;
    maxId = items.reduce((mx, m) => Math.max(mx, Number(m.id)||-1), maxId);

    const addHTML  = msgsToHTML(items.filter(m => Number(m.id) > since));
    const combined = clampHTML((cached?.html || '') + addHTML);   // ⬅️ clamp
    writeCache(key, { last: maxId, html: combined, isFull: false });
  }catch(_){}
}



  function cacheKeyForRoom(slug){ return `room:${slug}`; }
  function cacheKeyForDM(id){ return `dm:${id}`; }
  function activeCacheKey(){
    if (currentDM) return cacheKeyForDM(currentDM);
    return cacheKeyForRoom(currentRoom);
  }
function applyCache(key){
  const cached = ROOM_CACHE.get(key);
  if (cached){
    pubList.innerHTML = cached.html;

    // 🔧 prune immediately so layout stays cheap
    const trimmed = pruneList(pubList, 180);

    hydrateMessageIndexFromList(pubList);

    const dist = Number.isFinite(cached.bottomDist) ? cached.bottomDist : 0;

    const merged = {
      ...cached,
      last: Number(cached.last) || -1,
      html: pubList.innerHTML,
      bottomDist: dist,
      isFull: trimmed ? false : cached.isFull === true
    };

    // 🔁 re-stash the trimmed HTML so the cache doesn't bloat
    writeCache(key, merged);

    pubList.dataset.last = String(merged.last);

    requestAnimationFrame(()=>{
      pubList.scrollTop = Math.max(0, pubList.scrollHeight - pubList.clientHeight - dist);
    });

    watchNewImages(pubList);

    if (AUTO_SCROLL && dist < 20) scrollToBottom(pubList, false);
    return merged;
  }
  pubList.innerHTML = '';
  pubList.dataset.last = '-1';
  hydrateMessageIndexFromList(pubList);
  return null;
}

  function stashActive(){
  try{
    const bottomDist = Math.max(
      0,
      pubList.scrollHeight - pubList.scrollTop - pubList.clientHeight
    );
    writeCache(activeCacheKey(), {
      last: +pubList.dataset.last || -1,
      html: pubList.innerHTML,
      bottomDist
    });
  }catch(_){}
}

  async function refreshBlocked(){
    try{
      const js = await fetchJSON(API + '/block/list');
      const ids = (js?.ids || js?.blocked_ids || []);
      BLOCKED = new Set((Array.isArray(ids)?ids:[]).map(Number));

      if (currentDM && isBlocked(currentDM)) {
            muteFor(1200);     
        currentDM = null;
        applyCache(activeCacheKey());
        setComposerAccess();
        showView('vPublic');
      }

      [...ACTIVE_DMS].forEach(id => { if (isBlocked(id)) ACTIVE_DMS.delete(id); });
      saveDMActive(ACTIVE_DMS);
      renderUsers(); renderDMSidebar(); renderRoomTabs(); updateLeftCounts();
    }catch(_){}
  }

    logoutBtn?.addEventListener('click', async (e)=>{
      e.preventDefault();
      await doLogout();
    });

  async function fetchJSON(url){
    const r=await fetch(url,{credentials:'include',cache:'no-cache', headers:h});
    if(!r.ok){
      let js={}; try{ js = await r.json(); }catch(_){}
      if (js.err === 'kicked' || js.err === 'ip_banned') {
        const base = js.err === 'ip_banned' ? 'Regelbrott - Bannad' : 'Regelbrott - Kickad';
        const code = Number(js.block_id);
        const suffix = (Number.isFinite(code) && code > 0) ? ` (Felkod: ${code})` : '';
        alert(base + suffix);
        location.reload();
        return [];
      }

      throw new Error('fetch '+url);
    }
    return r.json();
  }

  async function loadHistorySnapshot(state){
    try {
      const target = state || desiredStreamState();
      if (!target) return false;

      const params = new URLSearchParams({ limit: String(FIRST_LOAD_LIMIT), since: '-1' });
      if (target.kind === 'dm') {
        params.set('to', String(target.to));
      } else {
        params.set('public', '1');
        params.set('room', target.room);
      }

      const items = await fetchJSON(`${API}/fetch?${params.toString()}`);
      if (!Array.isArray(items)) return false;

      handleStreamSync({ __snapshot: true, messages: items }, target);
      return true;
    } catch (_) {
      return false;
    }
  }

  async function verifyRecentDM(peerId) {
    const id = Number(peerId);
    if (!Number.isFinite(id) || id <= 0) return false;
    if (isBlocked(id)) return false;

    const inflight = DM_RECENT_VERIFY_INFLIGHT.get(id);
    if (inflight) {
      try { return await inflight; } catch (_) { return false; }
    }

    const task = (async () => {
      try {
        const params = new URLSearchParams({
          to: String(id),
          since: '-1',
          limit: '12',
        });

        const items = await fetchJSON(`${API}/fetch?${params.toString()}`);
        if (!Array.isArray(items) || !items.length) return false;

        if (currentDM !== id) return false;

        const normalized = items
          .map(m => ({
            ...m,
            id: Number(m.id),
            sender_id: Number(m.sender_id),
            recipient_id: m.recipient_id == null ? null : Number(m.recipient_id),
          }))
          .filter(m => Number.isFinite(m.id) && m.sender_id === id)
          .slice(-DM_RECENT_VERIFY_COUNT);

        if (!normalized.length) return false;

        const list = document.getElementById('kk-pubList');
        if (!list) return false;

        const currentLast = +list.dataset.last || -1;

        const missingOlder = normalized.filter(m => {
          const mid = Number(m.id);
          if (!Number.isFinite(mid)) return false;
          if (list.querySelector(`li.item[data-id="${mid}"]`)) return false;
          return mid <= currentLast;
        });

        if (missingOlder.length) {
          if (currentDM !== id) return false;
          return await loadHistorySnapshot({ kind: 'dm', to: id });
        }

        const unseen = normalized.filter(m => {
          const mid = Number(m.id);
          if (!Number.isFinite(mid)) return false;
          if (list.querySelector(`li.item[data-id="${mid}"]`)) return false;
          return mid > currentLast;
        });

        if (!unseen.length) return false;

        if (currentDM !== id) return false;

        unseen.sort((a, b) => Number(a.id) - Number(b.id));
        handleStreamSync({ messages: unseen }, { kind: 'dm', to: id });
        return true;
      } catch (_) {
        return false;
      }
    })();

    DM_RECENT_VERIFY_INFLIGHT.set(id, task);

    try {
      return await task;
    } finally {
      if (DM_RECENT_VERIFY_INFLIGHT.get(id) === task) {
        DM_RECENT_VERIFY_INFLIGHT.delete(id);
      }
    }
  }
  function esc(s){ const d=document.createElement('div'); d.textContent=s; return d.innerHTML; }
  function escAttr(s){ return (s==null?'':String(s)).replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

  function truncateText(str, limit = 160){
    const arr = Array.from(String(str || ''));
    if (arr.length <= limit) return arr.join('');
    return arr.slice(0, limit).join('').trimEnd() + '…';
  }

  function messageExcerptFromContent(text, kind){
    const normalizedKind = String(kind || 'chat').toLowerCase();
    if (normalizedKind === 'image') return '[Bild]';
    const cleaned = String(text ?? '').replace(/\s+/g, ' ').trim();
    if (!cleaned) return '';
    return truncateText(cleaned, 160);
  }

  function rememberMessageMeta(m){
    if (!m || typeof m !== 'object') return null;
    const mid = Number(m.id ?? m.message_id ?? m.mid ?? 0);
    if (!Number.isFinite(mid) || mid <= 0) return null;
    const kind = String(m.kind || 'chat');
    const senderIdRaw = Number(m.sender_id ?? m.user_id ?? m.author_id ?? 0);
    const senderId = Number.isFinite(senderIdRaw) && senderIdRaw > 0 ? senderIdRaw : null;
    const senderName = m.sender_name != null && m.sender_name !== '' ? String(m.sender_name) : null;
    const excerptSource = m.excerpt ?? m.content ?? m.text ?? m.body ?? '';
    const excerpt = messageExcerptFromContent(excerptSource, kind);

    const entry = {
      id: mid,
      kind,
      sender_id: senderId,
      sender_name: senderName,
      excerpt,
    };

    if (m.is_explicit !== undefined) {
      entry.is_explicit = !!m.is_explicit;
    }

    if (m.reply_to_id != null) {
      const rid = Number(m.reply_to_id);
      entry.reply_to_id = Number.isFinite(rid) && rid > 0 ? rid : null;
    }
    if (m.reply_to_sender_id != null) {
      const rsid = Number(m.reply_to_sender_id);
      entry.reply_to_sender_id = Number.isFinite(rsid) && rsid > 0 ? rsid : null;
    }
    if (m.reply_to_sender_name != null && m.reply_to_sender_name !== '') {
      entry.reply_to_sender_name = String(m.reply_to_sender_name);
    }
    if (m.reply_to_excerpt != null && m.reply_to_excerpt !== '') {
      entry.reply_to_excerpt = String(m.reply_to_excerpt);
    }

    MESSAGE_INDEX.set(mid, entry);
    while (MESSAGE_INDEX.size > MESSAGE_INDEX_LIMIT) {
      const next = MESSAGE_INDEX.keys().next();
      if (next && !next.done) {
        MESSAGE_INDEX.delete(next.value);
      } else {
        break;
      }
    }

    return entry;
  }

  function forgetMessageById(id){
    const mid = Number(id);
    if (!Number.isFinite(mid) || mid <= 0) return;
    MESSAGE_INDEX.delete(mid);
  }

  function messagePreviewById(id){
    const mid = Number(id);
    if (!Number.isFinite(mid) || mid <= 0) return null;
    const cached = MESSAGE_INDEX.get(mid);
    if (cached) return cached;

    const li = pubList ? pubList.querySelector(`li.item[data-id="${mid}"]`) : null;
    if (li) {
      const kind = li.dataset.kind || (li.querySelector('.bubble.img') ? 'image' : 'chat');
      const senderId = Number(li.dataset.sid || 0) || null;
      const senderName = li.dataset.sname || null;
      const body = li.dataset.body || '';
      const entry = rememberMessageMeta({
        id: mid,
        kind,
        sender_id: senderId,
        sender_name: senderName,
        content: body,
        is_explicit: li.dataset.explicit === '1',
        reply_to_id: li.dataset.replyId ? Number(li.dataset.replyId) : null,
        reply_to_sender_name: li.dataset.replyName || null,
        reply_to_excerpt: li.dataset.replyExcerpt || null,
      });
      if (entry) return entry;
    }
    return null;
  }

  function hydrateMessageIndexFromList(container){
    if (!container) return;
    container.querySelectorAll('li.item').forEach(li => {
      const mid = Number(li.dataset.id || 0);
      if (!Number.isFinite(mid) || mid <= 0) return;
      const kind = li.dataset.kind || (li.querySelector('.bubble.img') ? 'image' : 'chat');
      const senderId = Number(li.dataset.sid || 0) || null;
      const senderName = li.dataset.sname || null;
      const body = li.dataset.body || '';
      rememberMessageMeta({
        id: mid,
        kind,
        sender_id: senderId,
        sender_name: senderName,
        content: body,
        reply_to_id: li.dataset.replyId ? Number(li.dataset.replyId) : null,
        reply_to_sender_name: li.dataset.replyName || null,
        reply_to_excerpt: li.dataset.replyExcerpt || null,
      });
    });
  }

  function replyPreviewMarkup(targetId, senderName, excerpt){
    const tid = Number(targetId);
    if (!Number.isFinite(tid) || tid <= 0) return '';
    const safeName = senderName ? esc(String(senderName)) : 'Okänd';
    const safeExcerpt = excerpt ? esc(String(excerpt)) : '';
    const excerptHtml = safeExcerpt !== '' ? `<span class="bubble-reply-preview-text">${safeExcerpt}</span>` : '';
    return `<button type="button" class="bubble-reply-preview" data-reply-jump="${tid}"><span class="bubble-reply-preview-name">${safeName}</span>${excerptHtml}</button>`;
  }

  function setComposerReply(targetId){
    const mid = Number(targetId);
    if (!Number.isFinite(mid) || mid <= 0) return;
    const info = messagePreviewById(mid);
    if (!info) {
      showToast('Meddelandet är inte tillgängligt ännu.');
      return;
    }

    const name = info.sender_name || 'Okänd';
    const excerpt = info.excerpt || '';

    COMPOSER_REPLY = {
      id: info.id,
      name,
      excerpt,
    };

    if (replyToInput) replyToInput.value = String(info.id);
    if (replyExcerptInput) replyExcerptInput.value = excerpt;

    if (replyBanner) replyBanner.hidden = false;
    if (replyBannerName) replyBannerName.textContent = name;
    if (replyBannerExcerpt) {
      replyBannerExcerpt.textContent = excerpt;
      if (excerpt) {
        replyBannerExcerpt.removeAttribute('hidden');
      } else {
        replyBannerExcerpt.setAttribute('hidden', '');
      }
    }

    dispatchComposerChange();

    try {
      const textarea = pubForm?.querySelector('textarea');
      textarea?.focus();
    } catch (_) {}
  }

  function clearComposerReply(){
    COMPOSER_REPLY = null;
    if (replyToInput) replyToInput.value = '';
    if (replyExcerptInput) replyExcerptInput.value = '';
    if (replyBanner) replyBanner.hidden = true;
    if (replyBannerName) replyBannerName.textContent = '';
    if (replyBannerExcerpt) {
      replyBannerExcerpt.textContent = '';
      replyBannerExcerpt.setAttribute('hidden', '');
    }

    dispatchComposerChange();
  }

  function focusReplyTarget(targetId){
    const mid = Number(targetId);
    if (!Number.isFinite(mid) || mid <= 0) return false;
    const container = pubList;
    if (!container) return false;
    const li = container.querySelector(`li.item[data-id="${mid}"]`);
    if (!li) return false;

    const top = li.offsetTop - 36;
    try {
      container.scrollTo({ top: Math.max(0, top), behavior: 'smooth' });
    } catch (_) {
      container.scrollTop = Math.max(0, top);
    }

    li.classList.add('reply-highlight');
    setTimeout(() => {
      if (li.isConnected) li.classList.remove('reply-highlight');
    }, 1600);

    return true;
  }
  function initials(n){ return (n||'').trim().split(' ').filter(Boolean).map(s=>s[0]||'').join('').slice(0,2).toUpperCase(); }

   function atBottom(el){
   const bottomDist = el.scrollHeight - el.scrollTop - el.clientHeight;
   return bottomDist <= 2; 
 }

  function maybeToggleFab(){ jumpBtn.classList.toggle('show', !atBottom(pubList)); }
  function showToast(msg){ toast.textContent = msg; toast.classList.add('show'); setTimeout(()=>toast.classList.remove('show'), 1200); }
  function isVisible(el){ return !!(el && (el.offsetWidth || el.offsetHeight || el.getClientRects().length)); }
  function debounce(fn, ms){ let t; return (...a)=>{ clearTimeout(t); t=setTimeout(()=>{ try{ fn(...a); }catch(e){} }, ms); }; }
  function scrollToBottom(el, smooth = false){
  const instant = () => { el.scrollTop = el.scrollHeight; };
  const animated = () => {
    try { el.scrollTo({ top: el.scrollHeight, behavior: 'smooth' }); }
    catch(_) { instant(); }
  };
  requestAnimationFrame(()=>{
    (smooth ? animated : instant)();
    requestAnimationFrame(smooth ? animated : instant); 
  });
}

function escapeRegExp(str){ return String(str||'').replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }
function textMentionsName(text, name){
  if (!text || !name) return false;
  const safe = escapeRegExp(String(name).trim());
  // Match: start or non-word, then @Name, then non-word or end
  const pat = new RegExp(`(^|\\W)@${safe}(?=\\W|$)`, 'i');
  return pat.test(String(text));
}


function watchNewImages(container){
  container.querySelectorAll('img.imgmsg:not([data-watch])').forEach(img=>{
    img.dataset.watch = '1';
    if (IMG_BLUR && img.dataset.explicit === '1') img.removeAttribute('data-unblurred');
    img.addEventListener('load', ()=>{
      if (AUTO_SCROLL || atBottom(container)) scrollToBottom(container, false);
    }, { once: true });
  });
}

// How many <li.item> to keep per cached view
const CACHE_CAP = 120;

/**
 * Clamp an HTML string that contains <li class="item">…</li> rows
 * to the last CACHE_CAP rows. Works without touching the live DOM.
 */
function clampHTML(html){
  const tmp = document.createElement('ul');
  tmp.innerHTML = html;
  const items = tmp.querySelectorAll('li.item');
  const extra = Math.max(0, items.length - CACHE_CAP);
  for (let i = 0; i < extra; i++) items[i].remove();
  return tmp.innerHTML;
}

function renderList(el, items, options = {}){
  const { allowOlder = false, rebuild = false } = options || {};

  const lastSeen = +el.dataset.last || -1;
  let maxId = allowOlder ? -1 : lastSeen;

  const wasAtBottom = atBottom(el);
  const meIdNum = Number(ME_ID);

  const frag = document.createDocumentFragment();
  let mentionAdded   = false;
  let appendedCount  = 0;
  let anyFromOthers  = false;

  let pendingFrag = null;
  if (rebuild) {
    pendingFrag = document.createDocumentFragment();
    const pendingNodes = Array.from(el.querySelectorAll('li.item[data-temp]'));
    pendingNodes.forEach(node => {
      pendingFrag.appendChild(node);
    });
    el.innerHTML = '';
  }

  items.forEach(m=>{
    const mid = Number(m.id);
    if (!Number.isFinite(mid)) return;

    if (allowOlder) {
      const duplicate = el.querySelector(`li.item[data-id="${mid}"]`);
      if (duplicate) duplicate.remove();
    }

    if (mid > maxId) maxId = mid;
    // 🔔 moderation events (wake-only, do not render)
    const kind = (m.kind || 'chat');
    if (kind.indexOf('mod_') === 0) {
      try {
        const data = JSON.parse(m.content || '{}');
        if (data && data.action === 'hide' && data.id) {
          const el = document.querySelector(`li.item[data-id="${Number(data.id)}"]`);
          if (el) {
            el.remove();
            forgetMessageById(Number(data.id));
            try {
              writeCache(activeCacheKey(), {
                last: +pubList.dataset.last || -1,
                html: pubList.innerHTML
              });
            } catch(_) {}
          }
        } else if (data && data.action === 'unhide') {
          // optional refresh
        }
      } catch(_) {}
      return; // don't render mod events as chat bubbles
    }

    rememberMessageMeta(m);

    const fromId = Number(m.sender_id || 0);
    if (fromId && isBlocked(fromId)) return;          // hide blocked users
    if (!allowOlder && mid <= lastSeen) return;       // already rendered

    const li = document.createElement('li');
    li.dataset.id    = String(mid);
    li.dataset.sid   = String(m.sender_id || 0);
    li.dataset.sname = m.sender_name || '';
    li.dataset.kind  = kind || 'chat';

    if ((m.kind||'chat') === 'banner'){
      const payload = parseBannerPayload(m.content);
      const textHTML = normalizeBannerHTML(payload);
      const imageHTML = bannerImageHTML(payload);
      const styleAttr = bannerStyleAttr(payload);
      const body = payload.text || (typeof payload.html === 'string' ? payload.html : '');
      li.className = 'item banner';
      li.dataset.body = body;
      li.innerHTML = `<div class="banner-bubble"${styleAttr}>${textHTML}${imageHTML}</div>`;
    } else {
      const roleClass = isAdminById(m.sender_id) ? ' admin' : '';
      li.className = 'item ' + (m.sender_id===ME_ID? 'me':'them') + roleClass;
      const who = m.sender_name || 'Okänd';

      rememberName(m.sender_id, m.sender_name);
      const gender = genderById(m.sender_id);

      const when = new Date((m.time||0)*1000).toLocaleTimeString('sv-SE',{hour:'2-digit',minute:'2-digit'});
      const metaHTML = `<div class="bubble-meta small">${genderIconMarkup(gender)}<span class="bubble-meta-text">${who===ME_NM?'':esc(who)}<br>${esc(when)}</span></div>`;

      let bubbleHTML = '';
      const isImage = (m.kind||'chat') === 'image';
      const isExplicit = isImage ? !!m.is_explicit : false;
      const rawContent = String(m.content || '');
      const senderNum = Number(m.sender_id);
      const canReply = senderNum > 0 && senderNum !== meIdNum;
      li.dataset.body = isImage ? '[Bild]' : rawContent;
      if (isImage) {
        li.dataset.explicit = isExplicit ? '1' : '0';
      }

      const replyTargetId = Number(m.reply_to_id || 0) > 0 ? Number(m.reply_to_id) : null;
      let replySenderName = m.reply_to_sender_name || null;
      let replyExcerpt = m.reply_to_excerpt || null;
      let replyParentSenderId = null;
      const replyParentHint = Number(m.reply_to_sender_id || 0);
      if (Number.isFinite(replyParentHint) && replyParentHint > 0) {
        replyParentSenderId = replyParentHint;
      }
      let replyPreview = null;
      if (replyTargetId) {
        replyPreview = messagePreviewById(replyTargetId);
        if (replyPreview) {
          if (!replySenderName && replyPreview.sender_name) replySenderName = replyPreview.sender_name;
          if ((!replyExcerpt || replyExcerpt === '') && replyPreview.excerpt) replyExcerpt = replyPreview.excerpt;
          if (!replyParentSenderId) {
            const previewSenderId = Number(replyPreview.sender_id || replyPreview.user_id || replyPreview.author_id || 0);
            if (Number.isFinite(previewSenderId) && previewSenderId > 0) {
              replyParentSenderId = previewSenderId;
            }
          }
        }
        li.dataset.replyId = String(replyTargetId);
        li.dataset.replyName = replySenderName || '';
        li.dataset.replyExcerpt = replyExcerpt || '';
      } else {
        delete li.dataset.replyId;
        delete li.dataset.replyName;
        delete li.dataset.replyExcerpt;
      }

      if (replyTargetId) {
        rememberMessageMeta({
          id: mid,
          kind: m.kind,
          sender_id: m.sender_id,
          sender_name: m.sender_name,
          content: rawContent,
          reply_to_id: replyTargetId,
          reply_to_sender_name: replySenderName,
          reply_to_excerpt: replyExcerpt,
        });
      }

      let repliesToMe = false;
      if (replyTargetId && senderNum !== meIdNum) {
        if (Number.isFinite(replyParentSenderId) && replyParentSenderId > 0) {
          repliesToMe = replyParentSenderId === meIdNum;
        } else if (replySenderName && typeof ME_NM === 'string' && ME_NM !== '') {
          repliesToMe = replySenderName === ME_NM;
        }
      }

      const replyPreviewHTML = replyTargetId ? replyPreviewMarkup(replyTargetId, replySenderName, replyExcerpt) : '';
      const replyButtonHTML = canReply ? `<button type="button" class="bubble-reply-btn" data-reply-source="${mid}" aria-label="Svara"><span class="${MATERIAL_ICON_CLASS}" aria-hidden="true">reply</span></button>` : '';

      if (isImage) {
        const u   = rawContent.trim();
        const alt = `Bild från ${m.sender_id === ME_ID ? 'dig' : who}`;
        const badge = isExplicit ? '<span class="imgbadge" aria-label="Markerad som XXX">XXX</span>' : '';
        bubbleHTML = `<div class="bubble img${isExplicit ? ' is-explicit' : ''}">${replyButtonHTML}${replyPreviewHTML}${badge}
          <img class="imgmsg" src="${escAttr(u)}" data-explicit="${isExplicit ? '1' : '0'}" alt="${escAttr(alt)}" loading="lazy" decoding="async">
        </div>`;
        if (repliesToMe) {
          mentionAdded = true;
        }
      } else {
        const txt = rawContent;
        const isMentionToMe = textMentionsName(txt, ME_NM) && m.sender_id !== ME_ID;
        const mentionClass = isMentionToMe ? ' mention' : '';
        bubbleHTML = `<div class="bubble${mentionClass}">${replyButtonHTML}${replyPreviewHTML}<div class="bubble-text">${esc(txt)}</div></div>`;
        if (isMentionToMe) {
          li.dataset.mention = '1';
        }
        if (isMentionToMe || repliesToMe) {
          mentionAdded = true;
        }
      }

      li.innerHTML = `${bubbleHTML}${metaHTML}`;
    }

    frag.appendChild(li);
    // Admin: show a 6s preview under the sender for newly arrived messages
    if (IS_ADMIN) {
      const isNew = Number(m.id) > lastSeen;
      if (isNew) updateLastMessageFromMessage(m);
    }

    appendedCount++;
    if (Number(m.sender_id) !== Number(ME_ID)) anyFromOthers = true;
  });

  if (appendedCount > 0) {
    el.appendChild(frag);

    if (pendingFrag && pendingFrag.childNodes.length) {
      el.appendChild(pendingFrag);
    }

    // update last id only when we actually appended
    el.dataset.last = String(maxId);

    watchNewImages(el);

    const shouldSnap = (AUTO_SCROLL || wasAtBottom);
    if (shouldSnap) scrollToBottom(el, false);

    // Sounds: mention has priority; otherwise generic notif if user likely didn't see it
    try {
      const userAway = !isInteractive();
      if (mentionAdded && !soundsMuted() && !currentChatMuted()) {
      const snd = (typeof mentionSound !== 'undefined' && mentionSound)
        ? mentionSound
        : document.getElementById('kk-mentionSound');
      if (snd) {
        snd.playbackRate = 1.35;
        snd.currentTime = 0;
        snd.play()?.catch(()=>{});
      }
    } else if (anyFromOthers && (userAway || !atBottom(el)) && !currentChatMuted()) {
      try { playNotifOnce(); } catch(_) {}
    }

    } catch(_) {}

    maybeToggleFab();

    // Trigger read-marking for newly visible messages
    try { markVisible(el); } catch(_){}
  } else if (pendingFrag && pendingFrag.childNodes.length) {
    el.appendChild(pendingFrag);
    if (rebuild) {
      el.dataset.last = '-1';
    }
  } else {
    // nothing appended; keep existing dataset.last unchanged
    if (rebuild) {
      el.dataset.last = '-1';
    }
  }
}

// === Receipts: keep only "Skickad" (no read/seen) ==========
function computeReceipt(m /*, isDM */){
  // Treat any persisted/sent message by me as "Skickad".
  // We IGNORE any "read", "seen", or recipient watermark flags.
  const isSent =
    !!m?.id ||
    !!m?.sentAt ||
    m?.status === 'sent' ||
    m?.status === 'delivered';

  return isSent
    ? { text: 'Skickad', read: false }   // never mark as read
    : { text: '',       read: false };
}

function updateReceipts(el, items /*, isDM */){
  const byId = new Map(items.map(m => [m.id, m]));

  el.querySelectorAll('li.item.me').forEach(li => {
    const id = li.getAttribute('data-id');
    const m  = byId.get(id);
    const rec = computeReceipt(m);

    // Remove any old read-specific nodes/classes
    li.querySelectorAll('.receipt.read, .receipt .read, .receipt[data-read="1"]').forEach(n => n.remove());

    let span = li.querySelector('.receipt');
    if (!span) {
      span = document.createElement('span');
      span.className = 'receipt';
      li.appendChild(span);
    }

    // Only ever show "Skickad"
    span.textContent = rec.text;           // "Skickad" or ''
    span.removeAttribute('data-read');
    span.classList.remove('read');
  });
}



// --- Tunables --------------------------------------------------------------
const READS_DEFAULTS = Object.freeze({
  debounceMs: 8000,   // default debounce
  bottomPx:   32      // how close to bottom counts as "near the bottom"
});

function isNearBottom(el, px) {
  if (!el) return false;
  const bottomDist = el.scrollHeight - el.scrollTop - el.clientHeight;
  return bottomDist <= px;
}

// Replace your existing queueMark with this version
const queueMark = (() => {
  let pendingDM = null;
  let pendingRoom = null;
  let timer = null;
  let opts  = { ...READS_DEFAULTS };

  function shouldSendNow() {
    if (!isInteractive()) return false;
    return isNearBottom(pubList, opts.bottomPx);
  }

  function scheduleFlush() {
    if (timer != null) return;
    timer = setTimeout(() => {
      timer = null;
      flush(false).catch(() => {});
    }, opts.debounceMs);
  }

  async function flush(force) {
    if (!force && !shouldSendNow()) {
      scheduleFlush();
      return;
    }

    const dmPayload = pendingDM && pendingDM.peer && pendingDM.lastId > 0
      ? { ...pendingDM }
      : null;
    const roomPayload = pendingRoom && pendingRoom.slug && pendingRoom.lastId > 0
      ? { ...pendingRoom }
      : null;

    pendingDM = null;
    pendingRoom = null;

    if (!dmPayload && !roomPayload) {
      return;
    }

    const tasks = [];
    if (roomPayload) {
      tasks.push(markRoomSeen(roomPayload.slug, roomPayload.lastId));
    }
    if (dmPayload) {
      tasks.push(markDMSeen(dmPayload.peer, dmPayload.lastId));
    }

    try {
      await Promise.all(tasks);
    } catch (e) {
      console.warn('reads/mark failed', e);
    }
  }

  const api = function queueMark(ids, override = {}) {
    let maxId = 0;
    if (Array.isArray(ids) && ids.length) {
      maxId = Math.max(...ids
        .map(Number)
        .filter(n => Number.isFinite(n) && n > 0));
    }

    if (currentDM == null) {
      if (currentRoom && maxId > 0) {
        const last = pendingRoom?.lastId ?? 0;
        pendingRoom = { slug: currentRoom, lastId: Math.max(last, maxId) };
      }
      if (currentRoom && isInteractive()) {
        ROOM_UNREAD[currentRoom] = 0;
        renderRoomTabs();
        updateLeftCounts?.();
      }
    } else {
      if (maxId > 0) {
        const last = pendingDM?.lastId ?? 0;
        pendingDM = { peer: currentDM, lastId: Math.max(last, maxId) };
      }
      if (isInteractive()) {
        UNREAD_PER[currentDM] = 0;
        renderDMSidebar();
        renderRoomTabs();
        updateLeftCounts?.();
      }
    }

    opts = { ...READS_DEFAULTS, ...(override || {}) };

    if (pendingDM || pendingRoom) {
      scheduleFlush();
    }
  };

  api.flushNow = () => {
    if (timer != null) {
      clearTimeout(timer);
      timer = null;
    }
    flush(true).catch(() => {});
  };

  return api;
})();

function flushPendingReads() {
  try {
    if (typeof queueMark?.flushNow === 'function') {
      queueMark.flushNow();
    }
  } catch (_) {}
}



function markVisible(listEl){
  if (!isInteractive()) return;
  if (!isVisible(listEl)) return;

  const rect = listEl.getBoundingClientRect();
  const ids = [];
  const margin = 20;
  const viewTop = rect.top - margin;
  const viewBottom = rect.bottom + margin;

  listEl.querySelectorAll('li.item').forEach(li => {
    const r = li.getBoundingClientRect();

    if (r.bottom < viewTop || r.top > viewBottom) return;

    const visibleTop = Math.max(r.top, rect.top);
    const visibleBottom = Math.min(r.bottom, rect.bottom);
    const visibleHeight = visibleBottom - visibleTop;

    if (visibleHeight <= 0) return;

    const minVisible = Math.min(rect.height, r.height || (r.bottom - r.top));
    const threshold = Math.min(minVisible, Math.max(24, minVisible * 0.25));

    if (visibleHeight >= threshold) {
      const id = Number(li.dataset.id);
      if (id > 0) ids.push(id);
    }
  });

  if (ids.length) queueMark(ids);
}



  let USERS=[]; let UNREAD_PER={};
const LAST_MESSAGE_CACHE = new Map(); // userId -> last message summary
  let lastPubCounts = 0, lastDMCounts = 0;
  let DM_UNREAD_TOTAL = 0;
let ROOM_UNREAD = {};
let PREV_ROOM_UNREAD = {};
let PREV_UNREAD_PER  = {};

function normalizeUnreadMap(map) {
  const out = {};
  if (map && typeof map === 'object') {
    for (const [key, value] of Object.entries(map)) {
      out[key] = value ? 1 : 0;
    }
  }
  return out;
}

  function sortUsersForList(){
    const meId = Number(ME_ID);
    return [...USERS].sort((a,b)=>{
      const aid = Number(a.id);
      const bid = Number(b.id);
      if (Number.isFinite(meId)) {
        if (aid === meId) return -1;
        if (bid === meId) return 1;
      }
      const fa = a.flagged?1:0, fb = b.flagged?1:0;
      if (fa !== fb) return fb - fa;

      const ua = isBlocked(a.id) ? 0 : (UNREAD_PER[a.id]||0);
      const ub = isBlocked(b.id) ? 0 : (UNREAD_PER[b.id]||0);
      if (ua!==ub) return ub-ua;
      const na = (a.name||'').toLowerCase(), nb = (b.name||'').toLowerCase();
      return na.localeCompare(nb, 'sv');
    });
  }

    function isOnline(id){
      try { return Array.isArray(USERS) && USERS.some(x => Number(x.id) === Number(id)); }
      catch(_) { return false; }
    }

    function onlineActiveDmPeers(){
      return [...ACTIVE_DMS]
        .map(id => Number(id))
        .filter(id => Number.isFinite(id) && id > 0 && !isBlocked(id) && isOnline(id));
    }

    async function maybePrefetchInitialOnlineDMs(){
      if (INITIAL_DM_PREFETCH_DONE) return;
      const peers = onlineActiveDmPeers();
      if (!peers.length) return;
      INITIAL_DM_PREFETCH_DONE = true;
      try {
        peers.forEach(id => queuePrefetchDM(id));
      } catch(_) {}
      try {
        await Promise.all(peers.map(id => ensurePrefetchedDM(id)));
      } catch (err) {
        INITIAL_DM_PREFETCH_DONE = false;
        console.warn('initial DM prefetch failed', err);
      }
    }

    const NAME_CACHE = (()=>{ 
      try { const raw = localStorage.getItem('kk_name_cache'); return new Map(raw ? JSON.parse(raw) : []); }
      catch(_) { return new Map(); }
    })();
    function saveNameCache(){ try { localStorage.setItem('kk_name_cache', JSON.stringify([...NAME_CACHE])); } catch(_){} }
    function rememberName(id, nm){
      if (!id || !nm) return;
      const k = Number(id), v = String(nm);
      if (!NAME_CACHE.has(k) || NAME_CACHE.get(k) !== v){
        NAME_CACHE.set(k, v); 
        saveNameCache();
      }
    }

    function nameById(id){
      const u = (typeof USERS!=='undefined' && Array.isArray(USERS)) ? USERS.find(x => Number(x.id) === Number(id)) : null;
      if (u && u.name){ rememberName(id, u.name); return u.name; }
      return NAME_CACHE.get(Number(id)) || 'Okänd';
    }
    const GENDER_CACHE = (()=>{
      try { const raw = localStorage.getItem('kk_gender_cache'); return new Map(raw ? JSON.parse(raw) : []); }
      catch(_) { return new Map(); }
    })();
    function saveGenderCache(){ try { localStorage.setItem('kk_gender_cache', JSON.stringify([...GENDER_CACHE])); } catch(_){} }
    function rememberGender(id, gender){
      const k = Number(id);
      if (!Number.isFinite(k)) return;
      const v = String(gender ?? '').trim();
      if (!v) {
        if (GENDER_CACHE.has(k)) { GENDER_CACHE.delete(k); saveGenderCache(); }
        return;
      }
      if (!GENDER_CACHE.has(k) || GENDER_CACHE.get(k) !== v){
        GENDER_CACHE.set(k, v);
        saveGenderCache();
      }
    }
    function genderById(id){
      const n = Number(id);
      if (!Number.isFinite(n)) return '';
      try {
        const u = Array.isArray(USERS) ? USERS.find(x => Number(x.id) === n) : null;
        if (u && u.gender) {
          rememberGender(n, u.gender);
          return u.gender;
        }
      } catch(_) {}
      return GENDER_CACHE.get(n) || '';
    }
    const GENDER_ICON_FILES = Object.freeze({
      man: 'man.svg',
      woman: 'woman.svg',
      couple: 'couple.svg',
      'trans-mtf': 'trans-mtf.svg',
      'trans-ftm': 'trans-ftm.svg',
      nonbinary: 'nonbinary.svg',
      unknown: 'unknown.svg'
    });
    function normalizeGenderKey(gender){
      const raw = String(gender || '').trim().toLowerCase();
      if (!raw) return 'unknown';
      if (raw.includes('couple') || raw.includes('par')) return 'couple';
      if (raw.includes('trans') && raw.includes('mtf')) return 'trans-mtf';
      if (raw.includes('trans') && raw.includes('ftm')) return 'trans-ftm';
      if (raw.includes('non') && raw.includes('bin')) return 'nonbinary';
      if (raw.includes('other')) return 'nonbinary';
      if (raw.includes('woman') || raw.includes('kvinna') || raw.includes('female')) return 'woman';
      if (raw.includes('man') || raw.includes('male') || raw.includes('kille')) return 'man';
      return 'unknown';
    }
    function genderIconMarkup(gender){
      const key = normalizeGenderKey(gender);
      const file = GENDER_ICON_FILES[key] || GENDER_ICON_FILES.unknown;
      if (!file || !GENDER_ICON_BASE) return '';
      const src = `${GENDER_ICON_BASE}${file}`;
      return `<span class="bubble-gender" aria-hidden="true"><img class="bubble-gender-icon" src="${escAttr(src)}" alt="" role="presentation"></span>`;
    }
    function isAdminById(id){
  const n = Number(id);
  if (!Number.isFinite(n)) return false;
  try {
    const u = Array.isArray(USERS) ? USERS.find(x => Number(x.id) === n) : null;
    return !!(u && (u.is_admin === 1 || u.is_admin === true));
  } catch(_) { return false; }
}


  function lastMessageLine(u){
  if (!IS_ADMIN) return '';
  const info = resolveLastMessage(u);
  if (!info) return '';

  const kind = String(info.kind || 'chat').toLowerCase();
  const isImage = kind === 'image';
  const icon = iconMarkup(isImage ? 'photo_camera' : 'chat');

  let ctx = '';
  if (info.to) {
    const name = info.recipient_name || nameById(info.to) || `#${info.to}`;
    ctx = `DM→ ${name}`;
  } else if (info.room) {
    ctx = `#${info.room}`;
  }

  const rawText = String(info.text || '').trim();
  let text = isImage ? '[bild]' : rawText;
  if (!text) text = '…';

  const ts = Number(info.time);
  const timeLabel = Number.isFinite(ts) && ts > 0
    ? new Date(ts * 1000).toLocaleTimeString('sv-SE', { hour: '2-digit', minute: '2-digit' })
    : '';

  const ctxPart = ctx ? `${esc(ctx)}: ` : '';
  const timePart = timeLabel ? ` <span class="last-msg-time">${esc(timeLabel)}</span>` : '';

  return `<div class="last-msg-prev">${icon} ${ctxPart}<i>${esc(text)}</i>${timePart}</div>`;
}


function userRow(u){
  const isMe   = u.id === ME_ID;
  const adminIcon = u.is_admin ? `${iconMarkup('shield_person')} ` : '';
  const blocked = isBlocked(u.id);
  const unread = blocked ? 0 : (UNREAD_PER[u.id]||0);
  const badge  = unread ? `<span class="badge-sm" data-has>!</span>` : '';
  const name   = `${adminIcon}${esc(u.name)}${isMe ? ' (du)' : ''}`;
  const isHidden = !!(u.hidden);

  const dmBtn  = isMe ? '' : `<button class="openbtn" data-dm="${u.id}" ${blocked?'disabled':''}
                        aria-label="${blocked?'Avblockera för att skriva':'Öppna privat med '+esc(u.name)}">${iconMarkup('forward_to_inbox')}</button>`;

  let blockBtn = '';
  if (!isMe) {
    if (u.is_admin) {
      blockBtn = `<button class="modbtn" disabled title="Du kan inte blockera en admin">${iconMarkup('block')}</button>`;
    } else {
      blockBtn = `
        <button class="modbtn" type="button"
                data-block="${u.id}"
                aria-pressed="${blocked ? 'true' : 'false'}"
                aria-label="${blocked ? 'Avblockera' : 'Blockera'}"
                title="${blocked ? 'Avblockera' : 'Blockera'}">
          ${blocked ? iconMarkup('task_alt') : iconMarkup('block')}
        </button>`;
    }
  }

  const reportBtn = (!IS_ADMIN && !isMe)
    ? `<button class="openbtn" data-report="${u.id}" title="Rapportera användare" aria-label="Rapportera användare">${iconMarkup('report')}</button>`
    : '';

  const modBtns = (IS_ADMIN && !isMe)
    ? `<span class="modgrp">
         <button class="modbtn" data-log="${u.id}" title="Visa logg" aria-label="Visa logg">${iconMarkup('receipt_long')}</button>
         <button class="modbtn" data-kick="${u.id}" title="Kick" aria-label="Kick">${iconMarkup('logout')}</button>
         <button class="modbtn" data-ban="${u.id}" title="IP Ban" aria-label="IP-ban">${iconMarkup('public_off')}</button>
       </span>`
    : '';

  const incogBtn = (IS_ADMIN && isMe)
    ? `<button class="incogbtn" type="button" data-incognito="1" data-hidden="${isHidden ? 1 : 0}"
        aria-pressed="${isHidden ? 'true' : 'false'}"
        title="${isHidden ? 'Du är dold – klicka för att visa dig' : 'Du är synlig – klicka för att gömma dig'}"
        aria-label="${isHidden ? 'Visa mig i användarlistan' : 'Göm mig i användarlistan'}">${iconMarkup(isHidden ? 'visibility_off' : 'visibility')}</button>`
    : '';

  const logoutSelfBtn = isMe
    ? `<button class="logoutbtn" data-logout="1" title="Logga ut" aria-label="Logga ut">Logga ut<b>${iconMarkup('logout')}</b></button>`
    : '';

    const genderCol = u.gender
      ? `<div class="user-gender-col" title="${esc(u.gender)}">
           ${genderIconMarkup(u.gender)}
         </div>`
      : '';
    
    return `<div class="user" data-flag="${u.flagged?1:0}">
      <div class="user-main">
        ${genderCol}
        <div class="user-text">
          <b>${name}</b>
          <div class="small">${esc(u.gender || '')}</div>
        </div>
      </div>
      <div class="user-actions">${badge}${dmBtn}${blockBtn}${reportBtn}${modBtns}${incogBtn}${logoutSelfBtn}</div>
      ${lastMessageLine(u)}
    </div>`;

}

  function renderUsers(){
    const q = (userSearch.value||'').toLowerCase().trim();
    const hasGenderFilters = activeGenderFilters.size > 0;
    const sorted = sortUsersForList().filter(u=>{
      const name = (u.name||'').toLowerCase();
      if (q && !name.includes(q)) return false;
      if (!hasGenderFilters) return true;
      const key = (typeof normalizeGenderKey === 'function')
        ? normalizeGenderKey(u.gender)
        : String(u.gender || '').trim().toLowerCase();
      return activeGenderFilters.has(key);
    });
    userListEl.innerHTML = sorted.map(userRow).join('');
    if (currentDM){ userListEl.querySelector(`[data-dm="${currentDM}"]`)?.setAttribute('aria-current','true'); }
    updateLeftCounts();
  }
  // Keep filter button highlighted when searching by name
  userSearch?.addEventListener('input', ()=>{
   updateFilterButtonState();
   renderUsers();
  });

userListEl.addEventListener('click', async (e)=>{

  const inc = e.target.closest('[data-incognito]');
    if (inc) {
      e.preventDefault();
      if (!IS_ADMIN) return;
      if (inc.dataset.pending === '1') return;
      inc.dataset.pending = '1';

      const willHide = inc.getAttribute('data-hidden') === '1' ? '0' : '1';
      const fd = new FormData();
      fd.append('csrf_token', CSRF);
      fd.append('hidden', willHide);

      try {
        const resp = await fetch(API + '/admin/visibility', { method: 'POST', body: fd, credentials: 'include', headers: h });
        const js = await resp.json().catch(()=>({}));
        if (resp.ok && js.ok) {
          const hiddenNow = Number(js.hidden) === 1;
          USERS = USERS.map(u => (Number(u.id) === Number(ME_ID) ? { ...u, hidden: hiddenNow } : u));
          renderUsers();
          showToast(hiddenNow ? 'Du är dold i användarlistan' : 'Du visas i användarlistan');
        } else {
          const err = js.err || 'Kunde inte uppdatera synlighet';
          alert(err);
        }
      } catch (_) {
        alert('Tekniskt fel');
      } finally {
        delete inc.dataset.pending;
      }
      return;
    }

  const lo = e.target.closest('[data-logout]');
    if (lo) {
      e.preventDefault();
      await doLogout();
      return;
    }

  const lg = e.target.closest('[data-log]');
  if (lg && IS_ADMIN) { openLogs(+lg.dataset.log); return; }

  const dm = e.target.closest('[data-dm]');
  if (dm) { openDM(+dm.dataset.dm); return; }

  const blk = e.target.closest('[data-block]');
  if (blk) {
    const id = +blk.dataset.block;
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('target_id', String(id));
    try{
      const r  = await fetch(API + '/block/toggle', { method:'POST', body: fd, credentials:'include', headers:h });
      const js = await r.json().catch(()=>({}));
      if (r.ok && js.ok) {
        if (js.now_blocked) BLOCKED.add(id); else BLOCKED.delete(id);

        if (js.now_blocked && currentDM === id) {
          currentDM = null;
          applyCache(activeCacheKey());
          setComposerAccess();
          showView('vPublic');
        }

        UNREAD_PER[id] = 0;
        if (isBlocked(id)) { ACTIVE_DMS.delete(id); saveDMActive(ACTIVE_DMS); }
        renderUsers(); renderDMSidebar(); renderRoomTabs(); updateLeftCounts();
        showToast(js.now_blocked ? 'Användare blockerad' : 'Användare avblockerad');
      } else {
        const err = js.err || 'Kunde inte uppdatera blockering';
        if (err === 'cant_block_admin') alert('Du kan inte blockera en admin.');
        else alert(err);
      }
    }catch(_){ alert('Tekniskt fel'); }
    return;
  }

  const rep = e.target.closest('[data-report]');
  if (rep) {
    const id = +rep.dataset.report;
    const whom = nameById(id);
    let reason = prompt(`Ange anledning till rapporten för ${whom}:`, REPORT_REASON_PLACEHOLDER);
    if (reason == null) return;
    reason = (reason || '').trim();
    if (!reason || reason === REPORT_REASON_PLACEHOLDER) { alert('Du måste ange en anledning.'); return; }

    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('reported_id', String(id));
    fd.append('reason', reason);

    try{
      const r  = await fetch(API + '/report', { method:'POST', body: fd, credentials:'include', headers:h });
      const js = await r.json().catch(()=>({}));
      if (r.ok && js.ok) {
        showToast('Rapporten är skickad. Tack!');
      } else {
        alert('Kunde inte skicka rapporten: ' + (js.err || r.status));
      }
    }catch(_){
      alert('Tekniskt fel vid rapportering.');
    }
    return;
  }

  const k  = e.target.closest('[data-kick]');
  if (k && IS_ADMIN) {
    const id = +k.dataset.kick;
    const mins = parseInt(prompt('Kicka hur många minuter?', '60')||'60',10);
    if (!mins || mins<1) return;
    const cause = prompt('Orsak (valfritt):','')||'';
    const fd = new FormData(); fd.append('csrf_token', CSRF); fd.append('user_id', id); fd.append('minutes', String(mins)); fd.append('cause', cause);
    const r = await fetch(API+'/moderate/kick', {method:'POST', body:fd, credentials:'include', headers:h});
    const js = await r.json().catch(()=>({}));
    if (js.ok){ showToast('Kickad'); applySyncPayload(await fetchJSON(`${API}/sync?public=1&room=${encodeURIComponent(currentRoom)}&since=${pubList.dataset.last||-1}`)); }  else { alert('Misslyckades: '+(js.err||'error')); }
    return;
  }

  const b  = e.target.closest('[data-ban]');
  if (b && IS_ADMIN) {
    const id = +b.dataset.ban;
    let mins = prompt('IP-ban i hur många minuter? (0 = för alltid)', '0');
    mins = Math.max(0, parseInt(mins || '0', 10));
    const cause = prompt('Orsak (OBS! Visas till användaren):','') || '';
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('user_id', id);
    fd.append('minutes', String(mins));
    fd.append('cause', cause);
    const r  = await fetch(API+'/moderate/ipban', {method:'POST', body: fd, credentials:'include', headers:h});
    const js = await r.json().catch(()=>({}));
    if (js.ok){ showToast('IP-bannad'); applySyncPayload(await fetchJSON(`${API}/sync?public=1&room=${encodeURIComponent(currentRoom)}&since=${pubList.dataset.last||-1}`)); }
 else { alert('Misslyckades: '+(js.err||'error')); }
    return;
  }
});


// --- Admin last-message helpers ----------------------------------------
function cacheLastMessage(id, info){
  const key = Number(id);
  if (!Number.isFinite(key)) return;
  if (info) {
    LAST_MESSAGE_CACHE.set(key, info);
  } else {
    LAST_MESSAGE_CACHE.delete(key);
  }
}

function resolveLastMessage(u){
  if (!u) return null;
  const id = Number(u.id ?? u.user_id ?? 0);
  if (!Number.isFinite(id) || id <= 0) return null;

  let info = null;
  if (u.last_message && typeof u.last_message === 'object') {
    info = coerceLastMessage({ last_message: u.last_message });
  }

  if (!info) {
    info = LAST_MESSAGE_CACHE.get(id) || null;
  }

  if (info) {
    LAST_MESSAGE_CACHE.set(id, info);
    return info;
  }

  return null;
}

function coerceLastMessage(raw){
  if (!raw || typeof raw !== 'object') return null;
  const src = raw.last_message && typeof raw.last_message === 'object'
    ? raw.last_message
    : raw;

  const roomVal = src.room ?? src.last_room ?? null;
  const toVal = src.to ?? src.recipient_id ?? src.last_recipient_id ?? null;
  const kindVal = src.kind ?? src.last_kind ?? 'chat';
  const timeVal = src.time ?? src.last_created_at ?? src.created_at ?? null;
  const textVal = src.text ?? src.content ?? src.last_content ?? '';
  const recipName = src.recipient_name ?? src.last_recipient_name ?? null;

  const normalized = {
    text: String(textVal || '').slice(0, 200),
    room: roomVal ? String(roomVal) : null,
    to: Number.isFinite(Number(toVal)) && Number(toVal) > 0 ? Number(toVal) : null,
    recipient_name: recipName ? String(recipName) : null,
    kind: String(kindVal || 'chat'),
    time: Number.isFinite(Number(timeVal)) ? Math.floor(Number(timeVal)) : null,
  };

  const meaningfulText = normalized.text.trim();
  if (!meaningfulText && !normalized.room && !normalized.to && normalized.kind.toLowerCase() !== 'image') {
    return null;
  }

  return normalized;
}

function updateLastMessageFromMessage(m){
  if (!IS_ADMIN) return;
  const sid = Number(m?.sender_id);
  if (!Number.isFinite(sid) || sid <= 0 || sid === ME_ID) return;

  const kind = String(m?.kind || 'chat');
  const info = {
    text: kind === 'image' ? '' : String(m?.content || '').trim().slice(0, 200),
    room: m?.recipient_id == null ? (m?.room || null) : null,
    to:   m?.recipient_id != null && Number(m.recipient_id) > 0 ? Number(m.recipient_id) : null,
    recipient_name: m?.recipient_name ? String(m.recipient_name) : null,
    kind,
    time: Number.isFinite(Number(m?.time)) ? Number(m.time)
        : (Number.isFinite(Number(m?.created_at)) ? Number(m.created_at) : Math.floor(Date.now()/1000)),
  };

  cacheLastMessage(sid, info);
  USERS = USERS.map(u => (Number(u.id) === sid ? { ...u, last_message: info } : u));
  renderUsers();
}



function normalizeUsersPayload(raw){
  if (!raw) return [];

  const list = Array.isArray(raw) ? raw
             : (raw && Array.isArray(raw.users)) ? raw.users
             : (raw && typeof raw === 'object') ? Object.values(raw)
             : [];

  const rows = list.map(u => {
    const id = Number(
      u.id ?? u.user_id ?? u.uid ?? u.chat_id ?? u.session_id ?? u.wp_id ?? u.wpUserID ?? 0
    );

    let name = (
      u.name ?? u.user_name ?? u.username ??
      u.display_name ?? u.displayName ??
      u.wp_username ?? u.user_login ?? u.wp_login ?? u.login ??
      u.nick ?? u.nickname ?? ''
    );
    name = String(name || '').trim();
    if (!name) {
      const alt = String(
        u.wp_username ?? u.user_login ?? u.login ?? ''
      ).trim();
      name = alt || (id ? `#${id}` : '');
    }

    let last_message = coerceLastMessage(u);
    if (!last_message) {
      const cached = LAST_MESSAGE_CACHE.get(id) || null;
      if (cached) last_message = cached;
    }
    if (last_message) cacheLastMessage(id, last_message);

    return {
      id,
      name,
      gender: u.gender ?? u.category ?? '',
      is_admin: !!(u.is_admin ?? u.admin ?? u.isAdmin),
      flagged: (Number(u.flagged ?? u.watchlist ?? u.watch_flag ?? 0) ? 1 : 0),
      hidden: Number(u.hidden ?? u.is_hidden ?? u.visibility ?? 0) === 1,
      last_message
    };
  }).filter(u => u.id);

  const dedup = new Map();
  rows.forEach(u => dedup.set(u.id, u));
  return [...dedup.values()];
}
async function roomHasMentionSince(slug){
  try{
    const js = await fetchJSON(`${API}/fetch?public=1&room=${encodeURIComponent(slug)}&limit=30`);
    if (!Array.isArray(js)) return false;

    return js.some(m => {
      const txt = String(m?.content || m?.text || m?.body || '');
      const sid = Number(m?.sender_id ?? m?.user_id ?? m?.author_id ?? 0);
      if (sid === Number(ME_ID)) return false;

      // try strict helper first
      let hit = false;
      try { if (typeof textMentionsName === 'function') hit = !!textMentionsName(txt, ME_NM); } catch(_) {}

      // then a permissive fallback (handles punctuation)
      if (!hit) {
        const safe = String(ME_NM||'').replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        if (safe) {
          const re = new RegExp(`(^|\\W)@${safe}(?=\\W|$)`, 'i');
          hit = re.test(txt);
        }
      }
      return hit;
    });
  } catch(_) {
    return false;
  }
}

function kkIsActiveSource(slugOrDmKey){
  // Prefer your existing room/DM active-state check:
  if (typeof isActiveRoom === 'function') return isActiveRoom(slugOrDmKey);
  // Fallback: compare against a global/current slug if you have one:
  if (window.currentRoomSlug) return window.currentRoomSlug === slugOrDmKey;
  return false;
}

function kkAnyPassiveMentionBump(js){
  const bumps = js.mention_bumps || {};
  for (const [key, val] of Object.entries(bumps)) {
    if (val && !kkIsActiveSource(key)) return true;
  }
  return false;
}


function applySyncPayload(js){
  if (js && Array.isArray(js.events)) {
    let mergedMessages = [];
    let mergedUnread = null;
    let mergedPresence = null;
    let mergedMention = null;

    js.events.forEach(ev => {
      if (!ev || typeof ev !== 'object') return;
      if (Array.isArray(ev.messages) && ev.messages.length) {
        mergedMessages = mergedMessages.concat(ev.messages);
      }
      if (ev.unread && typeof ev.unread === 'object') {
        mergedUnread = ev.unread;
      }
      if (Array.isArray(ev.presence)) {
        mergedPresence = ev.presence;
      }
      if (ev.mention_bumps && typeof ev.mention_bumps === 'object') {
        mergedMention = { ...(mergedMention || {}), ...ev.mention_bumps };
      }
    });

    if (mergedMessages.length) {
      const existing = Array.isArray(js.messages) ? js.messages : [];
      if (!existing.length) {
        js.messages = mergedMessages;
      } else {
        const seen = new Set(existing.map(m => Number(m?.id)));
        const additions = mergedMessages.filter(m => !seen.has(Number(m?.id)));
        js.messages = existing.concat(additions);
      }
    }
    if (mergedUnread) {
      js.unread = mergedUnread;
    }
    if (mergedPresence) {
      js.presence = mergedPresence;
    }
    if (mergedMention) {
      js.mention_bumps = { ...(js.mention_bumps || {}), ...mergedMention };
    }
  }


  // --- helper: play mention sound with throttling fallback ---
  function playMentionOnce(){
    try{
      if (typeof soundsMuted === 'function' && soundsMuted()) return;
      const el = document.getElementById('kk-mentionSound');
      if (el){
        el.currentTime = 0;
        el.play()?.catch(()=>{});
        if (typeof muteFor === 'function') muteFor(800);
        // simple client-side cooldown so we don't re-ring on rapid polls
        window.__kkMentionCooldownUntil = Date.now() + 3500;
        return;
      }
    }catch(_){}
    if (typeof playNotifOnce === 'function') playNotifOnce();
  }

  // cooldown helpers
  if (!window.__kkMentionCooldownUntil) window.__kkMentionCooldownUntil = 0;
  const canRingMention = () => Date.now() >= window.__kkMentionCooldownUntil;

  if (js && Array.isArray(js.presence)) {
    USERS = normalizeUsersPayload(js.presence);
    maybePrefetchInitialOnlineDMs();
    try { USERS.forEach(u => rememberGender(u.id, u.gender)); } catch(_){}
  }

  if (js && js.unread) {
    const unread = js.unread || {};

    // keep previous counts for comparison (for sounds)
    const prevRooms = { ...(ROOM_UNREAD || {}) };
    const prevDMs   = { ...(UNREAD_PER  || {}) };

    // update to latest counts from server
    UNREAD_PER  = normalizeUnreadMap(unread.per);
    ROOM_UNREAD = normalizeUnreadMap(unread.rooms);

    // zero out blocked users in DM counts
    Object.keys(UNREAD_PER).forEach(k => { if (isBlocked(+k)) UNREAD_PER[k] = 0; });

    // 🔔 Notification logic:
    try{
      const roomIncr = Object.keys(ROOM_UNREAD)
        .filter(slug => (ROOM_UNREAD[slug] || 0) > (prevRooms[slug] || 0));


      const dmIncr = Object.keys(UNREAD_PER)
       .filter(id => !isBlocked(+id) && (UNREAD_PER[id] || 0) > (prevDMs[id] || 0));

      let seenChanged = false;
      let muteSetChanged = false;
      dmIncr.forEach(id => {
        const numericId = Number(id);
        if (!Number.isFinite(numericId)) return;
        if (SEEN_DMS.has(numericId)) return;
        SEEN_DMS.add(numericId);
        seenChanged = true;
        const wasMuted = MUTED_DMS.has(numericId);
        applyMutePreferenceToChat('dm', numericId);
        if (wasMuted !== MUTED_DMS.has(numericId)) {
          muteSetChanged = true;
        }
      });
      if (seenChanged) {
        saveDMSeen(SEEN_DMS);
      }
      if (muteSetChanged) {
        saveSet(MUTE_DMS_KEY, MUTED_DMS);
      }

      // Prefetch newly-bumped sources so switching feels instant.
      const isActiveRoom = (slug) => currentDM == null && slug === currentRoom;
      const isActiveDM   = (id)   => currentDM != null && Number(id) === Number(currentDM);

      try {
        roomIncr
          .filter(slug => !isActiveRoom(slug))
          .forEach(slug => queuePrefetchRoom(slug));

        dmIncr
          .map(id => Number(id))
          .filter(id => !isActiveDM(id))
          .forEach(id => queuePrefetchDM(id));
      } catch(_) {}
      // --- ignore muted sources for sound decisions
      const roomIncrUnmuted = roomIncr.filter(slug => !isRoomMuted(slug));
      const dmIncrUnmuted   = dmIncr.filter(id => !isDmMuted(+id));

      const interactive = isInteractive();

      const passiveRoomBumped = roomIncrUnmuted.some(slug => !isActiveRoom(slug));
      const passiveDmBumped   = dmIncrUnmuted.some(id   => !isActiveDM(id));

      const shouldRing =
        ((!interactive) && (roomIncrUnmuted.length || dmIncrUnmuted.length)) ||
        (interactive && (passiveRoomBumped || passiveDmBumped));

      // Server-assisted mention hints — also ignore muted rooms and only ring on rising edge
      const mentionBumps = (js && js.mention_bumps) ? js.mention_bumps : {};
      const mentionPassiveBumped = Object.entries(mentionBumps)
        .some(([slug, isMention]) =>
          !!isMention &&
          !isRoomMuted(slug) &&
          !isActiveRoom(slug) &&
          (ROOM_UNREAD[slug]||0) > (prevRooms[slug]||0)
        );

      if (mentionPassiveBumped && canRingMention()) {
        playMentionOnce();
      } else if (shouldRing) {
        playNotifOnce();
      }

      // --- Ensure a tab exists for newly-bumped DMs
      dmIncr.forEach(did => {
        const id = Number(did);
        if (!ACTIVE_DMS.has(id)) {
          ACTIVE_DMS.add(id);
          saveDMActive(ACTIVE_DMS);
          if (!SEEN_DMS.has(id)) {
            SEEN_DMS.add(id);
            saveDMSeen(SEEN_DMS);
          }
          applyMutePreferenceToChat('dm', id);
          saveSet(MUTE_DMS_KEY, MUTED_DMS);
          // reflect it in the UI immediately
          try { renderDMSidebar(); } catch(_){}
          try { renderRoomTabs(); } catch(_){}
        }
      });

      // --- Optional: auto-open the first newly-bumped DM so it "pops up"
      if (AUTO_OPEN_DM_ON_NEW && currentDM == null) {
        const first = dmIncr.find(did => !isBlocked(+did));
        if (first) {
          try { openDM(Number(first)); } catch(_) {}
        }
      }
    }catch(_){}

    // ⬇️ Optimistically clear active badge (prevents “stuck” counters).
    if (isInteractive()) {
      if (currentDM != null) {
        UNREAD_PER[currentDM] = 0;
      } else if (currentRoom) {
        ROOM_UNREAD[currentRoom] = 0;
      }
    }

    // recompute total DM unread after adjustments
    DM_UNREAD_TOTAL = Object.entries(UNREAD_PER)
      .reduce((n,[k,v]) => n + (isBlocked(+k) ? 0 : (v ? 1 : 0)), 0);
    

    
    // existing renders follow
    renderUsers();
    renderRoomsSidebar();
    renderDMSidebar();
    renderRoomTabs();
    updateLeftCounts();

  }

  renderUsers();
  renderRoomsSidebar();
  renderDMSidebar();
  renderRoomTabs();
  updateLeftCounts();
}


let ROOMS = [];
let currentRoom = 'lobby';
let currentDM = null;
let LAST_OPEN_DM = null;

  function defaultRoomSlug(){

    const gen = ROOMS.find(r=> r.slug==='lobby' && r.allowed);
    if (gen) return gen.slug;
    const firstAllowed = ROOMS.find(r=> r.allowed);
    if (firstAllowed) return firstAllowed.slug;
    return ROOMS[0]?.slug || '';
  }

  function ensureJoinedBaseline(){
    if (!ROOMS.length) return;
    const valid = new Set(ROOMS.map(r=>r.slug));

    [...JOINED].forEach(s=>{ if(!valid.has(s)) JOINED.delete(s); });
    if (JOINED.size===0){
      const d = defaultRoomSlug();
      if (d) { JOINED.add(d); saveJoined(JOINED); }
    }
  }

  function renderRoomsSidebar(){
    const rows = ROOMS.map(r=>{
      const joined = JOINED.has(r.slug);
      const lock = r.member_only ? `${iconMarkup('lock')} ` : '';
      const cur  = (r.slug===currentRoom && !currentDM) ? ' (aktiv)' : '';
      const unread = ROOM_UNREAD[r.slug]||0;
      const badge  = unread ? `<span class="badge-sm" data-has>!</span>` : '';
      const btn = joined
        ? `<button class="openbtn" data-leave="${r.slug}" title="Ta bort #${esc(r.slug)} från flikarna">${iconMarkup('logout')} Lämna</button>`
        : `<button class="openbtn" data-join="${r.slug}" title="Lägg till #${esc(r.slug)} i flikarna">${iconMarkup('add')} Gå med</button>`;
      const access = r.allowed ? '' : 'Endast medlemmar';
      return `<div class="user" data-room="${escAttr(r.slug)}">
        <div class="user-main">
          <b>${esc(r.title)}${cur}</b>
          <div class="small">${lock}${esc(access)}</div>
        </div>
        <div class="user-actions">${badge}${btn}</div>
      </div>`;
    }).join('');
    roomsListEl.innerHTML = rows || '<div class="user"><div class="user-main"><b>Inga rum</b></div></div>';
    updateLeftCounts();
  }

  function renderDMSidebar(){
    if (!dmSideListEl) return;
    const rows = [...ACTIVE_DMS]
      .filter(id => !isBlocked(id)) 
      .map(id => {
        const name = nameById(id) || ('#'+id);
        const unread = isBlocked(id) ? 0 : (UNREAD_PER[id] || 0);
        const badge  = unread ? `<span class="badge-sm" data-has>!</span>` : '';
        const cur    = currentDM === id ? ' aria-current="true"' : '';
        return `<div class="user" data-dm="${id}">
          <div class="user-main"><b>${esc(name)}</b></div>
          <div class="user-actions">
            ${badge}
            <button class="openbtn" data-open="${id}"${cur}>Öppna</button>
            <button class="modbtn" data-close="${id}" title="Stäng" aria-label="Stäng DM">${iconMarkup('delete')}</button>
          </div>
        </div>`;
      }).join('') || '<div class="user"><div class="user-main"><b>Inga privata chattar</b></div></div>';
    dmSideListEl.innerHTML = rows;
    updateLeftCounts();
  }

  function closeDM(id){
    id = Number(id);
    ACTIVE_DMS.delete(id);
    saveDMActive(ACTIVE_DMS);

    if (currentDM === id){
      flushPendingReads();
      muteFor(1200);
      stopStream();
      currentDM = null;
      applyCache(activeCacheKey());
      setComposerAccess();
      showView('vPublic');
      pollActive().catch(()=>{});
      openStream();
    }
    renderDMSidebar();
    renderRoomTabs();
    updateLeftCounts();
  }

  dmSideListEl?.addEventListener('click', (e)=>{
    const open = e.target.closest('[data-open]');
    if (open){ openDM(+open.dataset.open); renderRoomTabs(); return; }
    const close = e.target.closest('[data-close]');
    if (close){ closeDM(+close.dataset.close); return; }
  });

const msgSheet = document.getElementById('kk-msgSheet');
const msgClose = document.getElementById('kk-msgClose');
const msgTitle = document.getElementById('kk-msgTitle');
const actDm    = document.getElementById('kk-act-dm');
const actReport= document.getElementById('kk-act-report');
const actBlock = document.getElementById('kk-act-block');
let MSG_TARGET = { id: null, name: '', msgId: null, li: null, isMine: false };

const actHide = document.getElementById('kk-act-hide');

actHide?.addEventListener('click', async ()=>{
  if (!IS_ADMIN) { closeMsgSheet(); return; }

  const mid = Number(MSG_TARGET.msgId || 0);
  if (!mid) { alert('Kunde inte hitta meddelande-id.'); return; }

  const cause = prompt('Anledning (valfritt):', '') || '';

  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('message_id', String(mid));
  if (cause) fd.append('cause', cause);

  try{
    const r  = await fetch(API + '/moderate/hide-message', {
      method: 'POST',
      body: fd,
      credentials: 'include',
      headers: h
    });
    const js = await r.json().catch(()=>({}));

    if (r.ok && js.ok){

      try {
        MSG_TARGET.li?.remove();

        writeCache(activeCacheKey(), {
          last: +pubList.dataset.last || -1,
          html: pubList.innerHTML
        });
      } catch(_) {}

      showToast('Meddelandet dolt');
      closeMsgSheet();
    } else {
      alert(js.err || 'Kunde inte dölja meddelandet.');
    }
  } catch(_){
    alert('Tekniskt fel');
  }
});

function openMsgSheet(id, name, msgId = null, isMine = false, liEl = null){

  if (!IS_ADMIN && (!id || id === ME_ID)) return;

  MSG_TARGET = {
    id: id || null,
    name: name || nameById(id) || '',
    msgId: Number(msgId) || null,
    li: liEl || null,
    isMine: !!isMine
  };

  msgTitle.textContent = `Åtgärder för ${MSG_TARGET.name || '—'}`;

  const mine = !!MSG_TARGET.isMine || (MSG_TARGET.id === ME_ID);
  actDm?.toggleAttribute('disabled', mine);
  actReport?.toggleAttribute('disabled', mine);
  actBlock?.toggleAttribute('disabled', mine);
  if (mine) {

    actDm && (actDm.innerHTML = `${iconMarkup('forward_to_inbox')} Skicka DM`);
  }

  msgSheet.setAttribute('open','');
  document.documentElement.classList.add('kkchat-no-scroll');
}

function closeMsgSheet(){
  msgSheet.removeAttribute('open');
  document.documentElement.classList.remove('kkchat-no-scroll');
}
msgClose?.addEventListener('click', closeMsgSheet);
msgSheet?.addEventListener('click', (e)=>{ if (e.target === msgSheet) closeMsgSheet(); });
document.addEventListener('keydown', (e)=>{ if (e.key === 'Escape' && msgSheet.hasAttribute('open')) closeMsgSheet(); });

pubList.addEventListener('click', (e)=>{

  const replyBtn = e.target.closest('.bubble-reply-btn');
  if (replyBtn) {
    e.preventDefault();
    const li = replyBtn.closest('li.item');
    const target = li ? Number(li.dataset.id || replyBtn.dataset.replySource) : Number(replyBtn.dataset.replySource);
    if (Number.isFinite(target) && target > 0) {
      setComposerReply(target);
    }
    return;
  }

  const replyPreview = e.target.closest('.bubble-reply-preview');
  if (replyPreview) {
    e.preventDefault();
    const targetId = Number(replyPreview.dataset.replyJump);
    if (!focusReplyTarget(targetId)) {
      showToast('Originalmeddelandet är inte i historiken ännu.');
    }
    return;
  }

  if (e.target.closest('img.imgmsg')) return;

  const bubble = e.target.closest('.bubble');
  if (!bubble) return;

  const li    = bubble.closest('li.item');
  if (!li) return;

  const sid   = Number(li.dataset.sid || 0);
  const name  = li.dataset.sname || '';
  const mid   = Number(li.dataset.id  || 0);
  const mine  = li.classList.contains('me') || sid === Number(ME_ID);

  if (!IS_ADMIN && mine) return;

  openMsgSheet(sid, name, mid, mine, li);
});

actDm?.addEventListener('click', ()=>{
  try { openDM(MSG_TARGET.id); } catch(_) { 
    currentDM = MSG_TARGET.id; renderRoomTabs(); showView('vPublic'); setComposerAccess();
  }
  closeMsgSheet();
});
actReport?.addEventListener('click', async ()=>{
  let reason = prompt(`Ange anledning till rapporten för ${MSG_TARGET.name}:`, REPORT_REASON_PLACEHOLDER);
  if (reason == null) return;
  reason = (reason || '').trim();
  if (!reason || reason === REPORT_REASON_PLACEHOLDER) { alert('Du måste ange en anledning.'); return; }
  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('reported_id', String(MSG_TARGET.id));
  fd.append('reason', reason);
  try{
    const r = await fetch(API + '/report', { method:'POST', body: fd, credentials:'include', headers:h });
    const js = await r.json().catch(()=>({}));
    if (r.ok && js.ok) { showToast('Rapporten är skickad. Tack!'); closeMsgSheet(); }
    else { alert('Kunde inte skicka rapporten: ' + (js.err || r.status)); }
  }catch(_){ alert('Tekniskt fel vid rapportering.'); }
});
actBlock?.addEventListener('click', async ()=>{
  const id = MSG_TARGET.id;
  const fd = new FormData(); fd.append('csrf_token', CSRF); fd.append('target_id', String(id));
  try{
    const r  = await fetch(API + '/block/toggle', { method:'POST', body: fd, credentials:'include', headers:h });
    const js = await r.json().catch(()=>({}));
    if (r.ok && js.ok) {
      if (js.now_blocked) {
        BLOCKED.add(id);
        if (currentDM === id) closeDM(id);
        showToast('Användare blockerad');
      } else {
        BLOCKED.delete(id);
        showToast('Användare avblockerad');
      }
      UNREAD_PER[id] = 0;
      renderUsers(); renderDMSidebar(); renderRoomTabs(); updateLeftCounts();
      closeMsgSheet();
    } else {
      const err = js.err || 'Kunde inte uppdatera blockering';
      if (err === 'cant_block_admin') alert('Du kan inte blockera en admin.');
      else alert(err);
    }
  }catch(_){ alert('Tekniskt fel'); }
});
imgActDm?.addEventListener('click', ()=>{
  try { openDM(MSG_TARGET.id); } catch(_) {
    currentDM = MSG_TARGET.id; renderRoomTabs(); showView('vPublic'); setComposerAccess();
  }
  closeImagePreview();
});

imgActReport?.addEventListener('click', async ()=>{
  let reason = prompt(`Ange anledning till rapporten för ${MSG_TARGET.name}:`, REPORT_REASON_PLACEHOLDER);
  if (reason == null) return;
  reason = (reason || '').trim();
  if (!reason || reason === REPORT_REASON_PLACEHOLDER) { alert('Du måste ange en anledning.'); return; }

  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('reported_id', String(MSG_TARGET.id));
  fd.append('reason', reason);

  try{
    const r  = await fetch(API + '/report', { method:'POST', body: fd, credentials:'include', headers:h });
    const js = await r.json().catch(()=>({}));
    if (r.ok && js.ok) { showToast('Rapporten är skickad. Tack!'); closeImagePreview(); }
    else { alert('Kunde inte skicka rapporten: ' + (js.err || r.status)); }
  }catch(_){ alert('Tekniskt fel vid rapportering.'); }
});

imgActBlock?.addEventListener('click', async ()=>{
  const id = MSG_TARGET.id;
  const fd = new FormData(); fd.append('csrf_token', CSRF); fd.append('target_id', String(id));
  try{
    const r  = await fetch(API + '/block/toggle', { method:'POST', body: fd, credentials:'include', headers:h });
    const js = await r.json().catch(()=>({}));
    if (r.ok && js.ok) {
      if (js.now_blocked) {
        BLOCKED.add(id);
        if (currentDM === id) closeDM(id);
        showToast('Användare blockerad');
      } else {
        BLOCKED.delete(id);
        showToast('Användare avblockerad');
      }
      UNREAD_PER[id] = 0;
      renderUsers(); renderDMSidebar(); renderRoomTabs(); updateLeftCounts();

      const blocked = isBlocked(id);
      const blockLabel = blocked ? `${iconMarkup('task_alt')} Avblockera` : `${iconMarkup('block')} Blockera`;
      imgActBlock.innerHTML = blockLabel;
    } else {
      const err = js.err || 'Kunde inte uppdatera blockering';
      if (err === 'cant_block_admin') alert('Du kan inte blockera en admin.');
      else alert(err);
    }
  }catch(_){ alert('Tekniskt fel'); }
});

function renderRoomTabs(){

  const roomBtns = ROOMS
    .filter(r => JOINED.has(r.slug))
    .map(r => {
      const lock  = r.member_only ? ` ${iconMarkup('lock')}` : '';
      const count = ROOM_UNREAD[r.slug] || 0;
      const badge = count ? `<span class="badge" data-has>!</span>` : '';
      const selected = (r.slug===currentRoom && !currentDM) ? 'true' : 'false';
      return `<button class="tab" data-room="${r.slug}" aria-selected="${selected}">${roomMuteHTML(r.slug)}${esc(r.title)}${lock}${badge}</button>`;
    }).join('');

  const dmBtns = [...ACTIVE_DMS]
    .filter(id => !isBlocked(id))
    .map(id => {
      const name  = nameById(id) || ('#'+id);
      const count = UNREAD_PER[id] || 0;
      const badge = count ? `<span class="badge" data-has>!</span>` : '';
      const selected = currentDM===id ? 'true' : 'false';
      return `<button class="tab" data-dm="${id}" aria-selected="${selected}">
          ${dmMuteHTML(id)}${esc(name)}${badge}
          <span class="tab-close" data-close-dm="${id}" tabindex="0" aria-label="Stäng DM" title="Stäng DM">×</span>
        </button>`;
    }).join('');

  const activeRoom = currentRoom ? ROOMS.find(r => r.slug === currentRoom) : null;
  const activeNameRaw = currentDM != null
    ? (nameById(currentDM) || ('#' + currentDM))
    : (activeRoom?.title || 'den här fliken');
  const activeName = esc(activeNameRaw);
  const activeMuted = currentDM != null
    ? isDmMuted(currentDM)
    : isRoomMuted(currentRoom);
  const activeMuteLabel = activeMuted
    ? `${iconMarkup('notifications')} Slå på ljud för ${activeName}`
    : `${iconMarkup('notifications_off')} Tysta ${activeName}`;

  const autoLabel = AUTO_SCROLL
    ? `${iconMarkup('lock_open')} Autoscroll på (klicka för att låsa)`
    : `${iconMarkup('lock')} Autoscroll av (klicka för att låsa upp)`;

  const blurLabel = IMG_BLUR
    ? `${iconMarkup('visibility')} Visa XXX-bilder`
    : `${iconMarkup('visibility_off')} Dölj XXX-bilder`;

  const allMuted = allChatsMuted();
  const allMuteLabel = allMuted
    ? `${iconMarkup('notifications')} Slå på ljud för alla`
    : `${iconMarkup('notifications_off')} Tysta alla`;
  const muteNewChatsLabel = MUTE_NEW_CHATS
    ? `${iconMarkup('notifications')} Slå på ljud för nya chattar`
    : `${iconMarkup('notifications_off')} Tysta nya chattar`;

  const adminMenu = !HAS_ADMIN_TOOLS ? '' : `
    <div class="tab-settings tab-settings-admin${ADMIN_MENU_OPEN ? ' is-open' : ''}" data-admin-root>
      <button class="tabicons" data-admin-toggle="1"
              title="Adminverktyg"
              aria-label="Adminverktyg"
              aria-haspopup="menu"
              aria-expanded="${ADMIN_MENU_OPEN ? 'true' : 'false'}">${iconMarkup('build')}</button>
      <div class="tab-settings-menu${ADMIN_MENU_OPEN ? ' is-open' : ''}"
           role="menu"
           aria-hidden="${ADMIN_MENU_OPEN ? 'false' : 'true'}">
        ${ADMIN_LINKS.map(link => {
          const safeText = esc(link.text || '');
          const safeUrl  = escAttr(link.url || '#');
          return `<a class="tab-settings-item" href="${safeUrl}" role="menuitem" target="_blank" rel="noopener noreferrer">${safeText}</a>`;
        }).join('')}
      </div>
    </div>`;

  const controls = `
    <div class="tab-settings${SETTINGS_OPEN ? ' is-open' : ''}" data-settings-root>
        <button class="tabicons" data-settings-toggle="1"
                title="Fler inställningar"
                aria-label="Fler inställningar"
                aria-haspopup="menu"
                aria-expanded="${SETTINGS_OPEN ? 'true' : 'false'}">${iconMarkup('settings')}</button>
      <div class="tab-settings-menu${SETTINGS_OPEN ? ' is-open' : ''}"
           role="menu"
           aria-hidden="${SETTINGS_OPEN ? 'false' : 'true'}">
        <button type="button" class="tab-settings-item" data-settings-blur="1" role="menuitem">${blurLabel}</button>
        <button type="button" class="tab-settings-item" data-settings-autoscroll="1" role="menuitem">${autoLabel}</button>
        <button type="button" class="tab-settings-item" data-settings-mute-active="1" role="menuitem">${activeMuteLabel}</button>
        <button type="button" class="tab-settings-item" data-settings-mute-new="1" role="menuitem">${muteNewChatsLabel}</button>
        <button type="button" class="tab-settings-item" data-settings-mute-all="1" role="menuitem">${allMuteLabel}</button>
        <button type="button" class="tab-settings-item" data-settings-logout="1" role="menuitem">${iconMarkup('logout')} Logga ut</button>
      </div>
    </div>
    ${adminMenu}`;

  roomTabs.innerHTML = controls + roomBtns + dmBtns;
}

  function setComposerAccess(){
    const ta = pubForm.querySelector('textarea');
    const btn = pubForm.querySelector('button[type="submit"]');
    const imgB = pubUpBtn;
    const camB = pubCamBtn;
    const mentionB = mentionBtn;
    const toggleB = attachToggle;

    if (currentDM){
      ta.disabled = false; btn.disabled = false;
      if (imgB) imgB.disabled = false;
      if (camB) camB.disabled = false;
      if (mentionB) mentionB.disabled = false;
      if (toggleB) toggleB.disabled = false;
      const n = nameById(currentDM);
      ta.placeholder = `Skriv till ${n}…`;
      ta.setAttribute('aria-label', `Skriv privat till ${n}`);
      return;
    }

    const r = ROOMS.find(x=>x.slug===currentRoom);
    const allowed = r ? !!r.allowed : true;
    ta.disabled = !allowed; btn.disabled = !allowed;
    if (imgB) imgB.disabled = !allowed;
    if (camB) camB.disabled = !allowed;
    if (mentionB) mentionB.disabled = !allowed;
    if (toggleB) toggleB.disabled = !allowed;
    if (!allowed) closeAttachmentMenu();
    ta.placeholder = allowed ? 'Skriv ett meddelande…' : 'Endast för medlemmar';
    if (!allowed) ta.removeAttribute('aria-label'); else ta.setAttribute('aria-label', 'Skriv ett meddelande');
  }

roomTabs.addEventListener('click', async e => {
  const closer = e.target.closest('.tab-close[data-close-dm]');
  if (closer) {
    e.preventDefault();
    e.stopPropagation();
    closeDM(closer.getAttribute('data-close-dm'));
    return;
  }

  // Mute toggles inside tabs (stop the tab switch)
  const muteRoomBtn = e.target.closest('.tab-mute[data-mute-room]');
  if (muteRoomBtn) {
    e.preventDefault(); e.stopPropagation();
    toggleRoomMute(muteRoomBtn.getAttribute('data-mute-room'));
    return;
  }
  const muteDmBtn = e.target.closest('.tab-mute[data-mute-dm]');
  if (muteDmBtn) {
    e.preventDefault(); e.stopPropagation();
    toggleDmMute(muteDmBtn.getAttribute('data-mute-dm'));
    return;
  }

  const settingsToggle = e.target.closest('[data-settings-toggle]');
  if (settingsToggle) {
    e.preventDefault();
    e.stopPropagation();
    SETTINGS_OPEN = !SETTINGS_OPEN;
    if (SETTINGS_OPEN) ADMIN_MENU_OPEN = false;
    renderRoomTabs();
    return;
  }

  const adminToggle = e.target.closest('[data-admin-toggle]');
  if (adminToggle) {
    e.preventDefault();
    e.stopPropagation();
    ADMIN_MENU_OPEN = !ADMIN_MENU_OPEN;
    if (ADMIN_MENU_OPEN) SETTINGS_OPEN = false;
    renderRoomTabs();
    return;
  }

  const blurBtn = e.target.closest('[data-settings-blur]');
  if (blurBtn) {
    e.preventDefault();
    e.stopPropagation();
    setImageBlur(!IMG_BLUR);
    return;
  }

  const lockBtn = e.target.closest('[data-settings-autoscroll]');
  if (lockBtn) {
    e.preventDefault();
    e.stopPropagation();
    SETTINGS_OPEN = false;
    AUTO_SCROLL = !AUTO_SCROLL;
    localStorage.setItem(AUTO_KEY, AUTO_SCROLL ? '1' : '0');
    renderRoomTabs();
    if (AUTO_SCROLL) {
      const activeList = document.querySelector('.view[active] .list');
      if (activeList) scrollToBottom(activeList, true);
    }
    return;
  }

  const muteActiveBtn = e.target.closest('[data-settings-mute-active]');
  if (muteActiveBtn) {
    e.preventDefault();
    e.stopPropagation();
    SETTINGS_OPEN = false;
    toggleActiveChatMute();
    return;
  }

  const muteNewBtn = e.target.closest('[data-settings-mute-new]');
  if (muteNewBtn) {
    e.preventDefault();
    e.stopPropagation();
    setMuteNewChats(!MUTE_NEW_CHATS);
    return;
  }

  const muteAllBtn = e.target.closest('[data-settings-mute-all]');
  if (muteAllBtn) {
    e.preventDefault();
    e.stopPropagation();
    SETTINGS_OPEN = false;
    setAllMute(!allChatsMuted());
    return;
  }

  const logoutSettingsBtn = e.target.closest('[data-settings-logout]');
  if (logoutSettingsBtn) {
    e.preventDefault();
    e.stopPropagation();
    SETTINGS_OPEN = false;
    await doLogout();
    return;
  }

  // DM tab
  const dmBtn = e.target.closest('.tab[data-dm]');
  if (dmBtn) {
    flushPendingReads();
    muteFor(1200);
    const id = +dmBtn.dataset.dm;
    await openDM(id);
    return;
  }

  // Room tab
  const b = e.target.closest('.tab[data-room]');
  if (!b) return;

  const slug = b.dataset.room;
  const room = ROOMS.find(x => x.slug === slug);
  if (!room) return;
  if (!JOINED.has(slug)) return;
  if (!room.allowed) { showToast('Endast för medlemmar'); return; }

  flushPendingReads();
  muteFor(1200);
  stopStream();
  stashActive();
  currentDM = null;
  currentRoom = slug;
  ROOM_UNREAD[slug] = 0;
  renderRoomTabs();

  const cacheKey = cacheKeyForRoom(slug);
  let cacheEntry = applyCache(cacheKey);
  let cacheHit = !!cacheEntry;
  if (cacheHit && hasPendingPrefetch(cacheKey)) {
    const prefetched = await ensurePrefetchedRoom(slug);
    if (prefetched) {
      cacheEntry = applyCache(cacheKey) || cacheEntry;
      cacheHit = !!cacheEntry;
    }
  } else if (!cacheHit) {
    const prefetched = await ensurePrefetchedRoom(slug);
    if (prefetched) {
      cacheEntry = applyCache(cacheKey);
      cacheHit = !!cacheEntry;
    }
  }
  setComposerAccess();
  showView('vPublic');

  let snapshotPromise = null;
  if (cacheHit && cacheEntry?.isFull) {
    markVisible(pubList);
  } else {
    snapshotPromise = loadHistorySnapshot({ kind: 'room', room: slug });
  }

  const syncPromise = pollActive(true).catch(()=>{});
  openStream();
  if (snapshotPromise) {
    await snapshotPromise.catch(()=>{});
  }
  await syncPromise;
});

document.addEventListener('click', e => {
  let changed = false;
  if (SETTINGS_OPEN && !e.target.closest('[data-settings-root]')) {
    SETTINGS_OPEN = false;
    changed = true;
  }
  if (ADMIN_MENU_OPEN && !e.target.closest('[data-admin-root]')) {
    ADMIN_MENU_OPEN = false;
    changed = true;
  }
  if (changed) renderRoomTabs();
});

document.addEventListener('keydown', e => {
  if (e.key === 'Escape' && (SETTINGS_OPEN || ADMIN_MENU_OPEN)) {
    SETTINGS_OPEN = false;
    ADMIN_MENU_OPEN = false;
    renderRoomTabs();
  }
});

roomTabs.addEventListener('keydown', e => {
  // Treat Enter/Space as "activate"
  const isActivate = (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar');

  // Close DM (when focus is on the ×)
  const closer = e.target.closest('.tab-close[data-close-dm]');
  if (closer && isActivate) {
    e.preventDefault();
    e.stopPropagation();
    closeDM(closer.getAttribute('data-close-dm'));
    return;
  }

  // Toggle ROOM mute (when focus is on the bell inside a room tab)
  const mRoom = e.target.closest('.tab-mute[data-mute-room]');
  if (mRoom && isActivate) {
    e.preventDefault();
    e.stopPropagation();
    toggleRoomMute(mRoom.getAttribute('data-mute-room'));
    return;
  }

  // Toggle DM mute (when focus is on the bell inside a DM tab)
  const mDm = e.target.closest('.tab-mute[data-mute-dm]');
  if (mDm && isActivate) {
    e.preventDefault();
    e.stopPropagation();
    toggleDmMute(mDm.getAttribute('data-mute-dm'));
    return;
  }
});


leftTabs?.addEventListener('click', async (e) => {
  const btn = e.target.closest('button[data-view]');
  if (!btn) return;

  leftTabs.querySelectorAll('button[data-view]')
    .forEach(b => b.setAttribute('aria-selected', 'false'));
  btn.setAttribute('aria-selected', 'true');

  const v = btn.dataset.view;

  // Reset all views
  lvUsers?.removeAttribute('active');
  lvRooms?.removeAttribute('active');
  lvDMs?.removeAttribute('active');
  lvReports?.removeAttribute('active'); // NEW

  if (v === 'users') {
    lvUsers?.setAttribute('active', '');
  } else if (v === 'rooms') {
    lvRooms?.setAttribute('active', '');
  } else if (v === 'reports') {        // NEW
    lvReports?.setAttribute('active', '');
    try { await loadReports(); } catch (_) {}
  } else {
    lvDMs?.setAttribute('active', '');
  }
});

roomsListEl?.addEventListener('click', async (e) => {
  const j = e.target.closest('[data-join]');
  const l = e.target.closest('[data-leave]');
  if (!j && !l) return;

  if (j) {
    const slug = j.dataset.join;
    JOINED.add(slug);
    saveJoined(JOINED);
    applyMutePreferenceToChat('room', slug);
    saveSet(MUTE_ROOMS_KEY, MUTED_ROOMS);
    renderRoomsSidebar();
    renderRoomTabs();
    updateLeftCounts();
    return;
  }

  if (l) {
    const slug = l.dataset.leave;

    if (JOINED.size <= 1 && JOINED.has(slug)) { showToast('Minst ett rum måste vara aktivt'); return; }
    JOINED.delete(slug);
    saveJoined(JOINED);

    if (!currentDM && currentRoom === slug) {
      ensureJoinedBaseline();
      const next = ROOMS.find(r => JOINED.has(r.slug) && r.allowed)?.slug || defaultRoomSlug();
      if (next && next !== currentRoom) {
        flushPendingReads();
        muteFor(1200);
        stopStream();
        currentRoom = next;
        const cacheEntry = applyCache(cacheKeyForRoom(currentRoom));
        const cacheHit = !!cacheEntry;
        setComposerAccess();
        showView('vPublic');

        let snapshotPromise = null;
        if (cacheHit && cacheEntry?.isFull) {
          // ✅ mark reads immediately on cache hit
          markVisible(pubList);
        } else {
          snapshotPromise = loadHistorySnapshot({ kind: 'room', room: currentRoom });
        }

        const syncPromise = pollActive(true).catch(()=>{});
        openStream();
        if (snapshotPromise) {
          await snapshotPromise.catch(()=>{});
        }
        await syncPromise;
      }
    }

    renderRoomsSidebar();
    renderRoomTabs();
    updateLeftCounts();
  }
});


  async function loadRooms(){
    const rs = await fetchJSON(API+'/rooms');
    ROOMS = Array.isArray(rs) ? rs : [];
    ensureJoinedBaseline();

    if (!ROOMS.find(r=>r.slug===currentRoom) || !JOINED.has(currentRoom)){
      const next = ROOMS.find(r=> JOINED.has(r.slug) && r.allowed)?.slug || defaultRoomSlug();
      if (next) currentRoom = next;
    }

    renderRoomsSidebar();
    renderRoomTabs();
    setComposerAccess();
  }

  function updateLeftCounts(){
    try{
      const userCnt = USERS.length;
      // Sum unread counts for the rooms the user has active tabs for.
      const roomCnt = [...JOINED].length;
      const dmCnt   = DM_UNREAD_TOTAL;
      if (countUsersEl) countUsersEl.textContent = String(userCnt);
      if (countRoomsEl) countRoomsEl.textContent = String(roomCnt);
      if (countDMsEl)   countDMsEl.textContent   = String(dmCnt);
     if (countReportsEl) countReportsEl.textContent = String(OPEN_REPORTS_COUNT || 0);
    }catch(_){}
  }

  function pruneList(el, max = 300){
    const items = el.querySelectorAll('li.item');
    if (items.length > max){
      const removeCount = items.length - max;
      for (let i = 0; i < removeCount; i++) {
        const item = items[i];
        const id = Number(item?.dataset?.id || 0);
        if (Number.isFinite(id) && id > 0) forgetMessageById(id);
        item.remove();
      }
      return true;
    }
    return false;
  }
function safeAutoScroll(container, renderUpdates) {

  const wasAtBottom = atBottom(container);       
  const prevHeight  = container.scrollHeight;

  renderUpdates();

  const grew = container.scrollHeight > prevHeight;

  if (grew && (AUTO_SCROLL || wasAtBottom)) {
    scrollToBottom(container, false);
  }
}

function didAppendNew(payload, prevLast) {
  if (!Array.isArray(payload) || payload.length === 0) return false;
  for (let i = payload.length - 1; i >= 0; i--) {
    const id = Number(payload[i]?.id);
    if (Number.isFinite(id) && id > prevLast) return true;
  }
  return false;
}

function extractMaxMessageId(payload, context) {
  let maxId = -1;

  const consider = (item) => {
    if (!item || typeof item !== 'object') return;
    const mid = Number(item?.id);
    if (!Number.isFinite(mid)) return;

    if (context && context.kind === 'dm') {
      const sid = Number(item.sender_id);
      const rid = item.recipient_id == null ? null : Number(item.recipient_id);
      const isMine    = sid === Number(ME_ID) && rid === Number(context.to);
      const isTheirs  = sid === Number(context.to) && rid === Number(ME_ID);
      if (!isMine && !isTheirs) return;
    } else if (context && context.kind === 'room') {
      const slug = String(item.room || item.room_slug || item.roomSlug || '').trim();
      if (slug && slug !== context.room) return;
    }

    if (mid > maxId) {
      maxId = mid;
    }
  };

  const scan = (list) => {
    if (!Array.isArray(list) || !list.length) return;
    for (const item of list) consider(item);
  };

  if (payload && typeof payload === 'object') {
    scan(payload.messages);
    if (Array.isArray(payload.events)) {
      payload.events.forEach(ev => {
        if (ev && typeof ev === 'object') {
          scan(ev.messages);
        }
      });
    }
  }

  return maxId;
}

function updateListLastFromPayload(payload, context) {
      const active = desiredStreamState();
  if (!active || !context) return;

  if (context.kind === 'dm') {
    if (active.kind !== 'dm' || Number(active.to) !== Number(context.to)) {
      return;
    }
  } else if (context.kind === 'room') {
    if (active.kind !== 'room' || active.room !== context.room) {
      return;
    }
  } else {
    return;
  }
  const maxId = extractMaxMessageId(payload, context);
  if (!Number.isFinite(maxId) || maxId < 0) return;
  const current = +pubList.dataset.last || -1;
  if (maxId > current) {
    pubList.dataset.last = String(maxId);
  }
}

function handleStreamSync(js, context){
  if (!js || typeof js !== 'object') return;

  const active = desiredStreamState();
  if (!active) return;

  if (context?.kind === 'dm') {
    if (active.kind !== 'dm' || Number(active.to) !== Number(context.to)) return;
  } else {
    if (active.kind !== 'room' || active.room !== context.room) return;
  }

  applySyncPayload(js);

  const isSnapshot  = js?.__snapshot === true;
  const prevLast    = +pubList.dataset.last || -1;
  const isCold      = isSnapshot || prevLast < 0;
  const wasAtBottom = atBottom(pubList);

  const renderOpts  = isSnapshot ? { allowOlder: true, rebuild: true } : undefined;

  if (context.kind === 'room') {
    const payload = Array.isArray(js?.messages) ? js.messages : [];
    const items   = isCold ? payload.slice(-FIRST_LOAD_LIMIT) : payload;

    renderList(pubList, items, renderOpts);
    markVisible(pubList);
    watchNewImages(pubList);
    updateReceipts(pubList, items, /*isDM=*/false);

    if (items.length && (AUTO_SCROLL || wasAtBottom)) {
      scrollToBottom(pubList, false);
    }

    const currentLast = +pubList.dataset.last || -1;
    const allMax = items.reduce((mx, m) => Math.max(mx, Number(m.id) || -1), currentLast);
    pubList.dataset.last = String(allMax);

    const cacheKey = cacheKeyForRoom(context.room);
    const prevCache = ROOM_CACHE.get(cacheKey);
    const isFull = isSnapshot ? true : prevCache?.isFull === true;
    writeCache(cacheKey, {
      last: +pubList.dataset.last || -1,
      html: pubList.innerHTML,
      isFull
    });
  } else {
    const raw = Array.isArray(js?.messages) ? js.messages : [];
    const between = raw.filter(m => {
      const sid = Number(m.sender_id);
      const rid = m.recipient_id == null ? null : Number(m.recipient_id);
      return (sid === ME_ID && rid === context.to) || (sid === context.to && rid === ME_ID);
    });

    const mine = between.filter(m => {
      const sid = Number(m.sender_id);
      return !isBlocked(sid) || sid === ME_ID;
    });

    const items = (isCold ? mine.slice(-FIRST_LOAD_LIMIT) : mine).map(m => ({
      ...m,
      id: Number(m.id),
      sender_id: Number(m.sender_id),
      recipient_id: m.recipient_id == null ? null : Number(m.recipient_id)
    }));

    renderList(pubList, items, renderOpts);
    markVisible(pubList);
    watchNewImages(pubList);
    updateReceipts(pubList, items, /*isDM=*/true);

    if (items.length && (AUTO_SCROLL || wasAtBottom)) {
      scrollToBottom(pubList, false);
    }

    const currentLast = +pubList.dataset.last || -1;
    const allMax = items.reduce((mx, m) => Math.max(mx, Number(m.id) || -1), currentLast);
    pubList.dataset.last = String(allMax);

    const cacheKey = cacheKeyForDM(context.to);
    const prevCache = ROOM_CACHE.get(cacheKey);
    const isFull = isSnapshot ? true : prevCache?.isFull === true;
    writeCache(cacheKey, {
      last: +pubList.dataset.last || -1,
      html: pubList.innerHTML,
      isFull
    });
  }
}

async function pollActive(forceCold = false, options = {}){
  const { allowSuspended = false } = options || {};
  if (MULTITAB_LOCKED) return;
  const state = desiredStreamState();
  if (!state) return;

  if (forceCold) {
    ensureLeader(true);
  } else {
    ensureLeader(false);
  }

  if (!POLL_IS_LEADER) {
    requestLeaderSync(forceCold);
    return;
  }

  try {
    await performPoll(forceCold, { allowSuspended });
  } catch(_) {}
}

if (!isInteractive()) {
  handlePresenceChange({ force: true });

}

document.addEventListener('visibilitychange', () => {
  handlePresenceChange();
});

window.addEventListener('focus',  () => {
  WINDOW_FOCUSED = true;
  handlePresenceChange({ force: true, requestCold: true });
});

window.addEventListener('blur', () => {
  WINDOW_FOCUSED = false;
  handlePresenceChange();
});
window.addEventListener('online', () => { pollActive().catch(()=>{}); restartStream(); });
window.addEventListener('offline', () => {
  abortActivePoll();
  stopStream();
});


  function showView(id){ document.querySelectorAll('.view').forEach(v=>v.removeAttribute('active')); document.getElementById(id).setAttribute('active',''); }

async function openDM(id) {
  if (isBlocked(id)) { showToast('Du har blockerat denna användare'); return; }
  flushPendingReads();
  muteFor(1200);

  stopStream();

  // Remember current scroll/content before switching
  stashActive();

  // Ensure the DM appears in the sidebar/tabs (don't render yet)
  const numericId = Number(id);
  const alreadyActive = ACTIVE_DMS.has(numericId);
  ACTIVE_DMS.add(numericId);
  saveDMActive(ACTIVE_DMS);
  if (!alreadyActive) {
    if (!SEEN_DMS.has(numericId)) {
      SEEN_DMS.add(numericId);
      saveDMSeen(SEEN_DMS);
    }
    applyMutePreferenceToChat('dm', numericId);
    saveSet(MUTE_DMS_KEY, MUTED_DMS);
  }

  // 👉 Set state FIRST so renderers know which tab is active
  currentDM = numericId;
  LAST_OPEN_DM = currentDM;
  UNREAD_PER[currentDM] = 0;

  // Recompute total DM unread so the left badge updates immediately
  try {
    DM_UNREAD_TOTAL = Object.entries(UNREAD_PER)
      .reduce((sum, [k, v]) => sum + (isBlocked(+k) ? 0 : (v ? 1 : 0)), 0);
  } catch (_) {}

  // Now render UI that depends on currentDM
  renderDMSidebar();
  renderRoomTabs();
  updateLeftCounts?.();

  // Highlight the opened DM in the People list (if visible)
  userListEl.querySelectorAll('.openbtn[aria-current]').forEach(x => x.removeAttribute('aria-current'));
  userListEl.querySelector(`.openbtn[data-dm="${currentDM}"]`)?.setAttribute('aria-current', 'true');

  // 2) render from cache immediately
  const cacheKey = cacheKeyForDM(currentDM);
  let cacheEntry = applyCache(cacheKey);
  let cacheHit = !!cacheEntry;
  if (cacheHit && hasPendingPrefetch(cacheKey)) {
    const prefetched = await ensurePrefetchedDM(currentDM);
    if (prefetched) {
      cacheEntry = applyCache(cacheKey) || cacheEntry;
      cacheHit = !!cacheEntry;
    }
  } else if (!cacheHit) {
    const prefetched = await ensurePrefetchedDM(currentDM);
    if (prefetched) {
      cacheEntry = applyCache(cacheKey);
      cacheHit = !!cacheEntry;
    }
  }

  setComposerAccess();
  showView('vPublic');

  let snapshotPromise = null;
  if (cacheHit && cacheEntry?.isFull) {
    markVisible(pubList);
  } else {
    snapshotPromise = loadHistorySnapshot({ kind: 'dm', to: currentDM });
  }

  const syncPromise = pollActive(true).catch(()=>{});
  openStream();
  if (snapshotPromise) {
    await snapshotPromise.catch(()=>{});
  }
  const verifyPromise = verifyRecentDM(currentDM).catch(()=>{});
  await syncPromise;
  await verifyPromise;



  // Keep the scroll feeling nice
  if (AUTO_SCROLL && atBottom(pubList)) {
    scrollToBottom(pubList, false);
  }
}

mentionBtn?.addEventListener('click', (e) => {
  e.preventDefault();
  e.stopPropagation();
  closeAttachmentMenu();
  if (!pubTA) return;

  pubTA.focus();

  const i   = pubTA.selectionStart ?? pubTA.value.length;
  const txt = pubTA.value || '';
  const before = txt.slice(0, i);
  const after  = txt.slice(i);
  const needsSpace = i > 0 && /\S/.test(before.slice(-1));
  const insert = (needsSpace ? ' ' : '') + '@';

  pubTA.value = before + insert + after;

  const pos = (before + insert).length;
  pubTA.setSelectionRange(pos, pos);

  const q = (typeof currentMentionQuery === 'function')
              ? (currentMentionQuery(pubTA.value, pos) ?? '')
              : '';
  if (typeof renderMentionBox === 'function') {
    renderMentionBox(q);
  }
});

const mentionBox   = document.getElementById('kk-mentionBox');
const mentionSound = document.getElementById('kk-mentionSound');
let mentionIndex = -1;

function hideMentionBox(){ if (!mentionBox) return; mentionBox.removeAttribute('open'); mentionBox.style.display='none'; mentionIndex = -1; }
function showMentionBox(){ if (!mentionBox) return; mentionBox.style.display='block'; mentionBox.setAttribute('open',''); }
function mentionRows(){ return Array.from(mentionBox?.querySelectorAll('.row')||[]); }
function highlightMentionRow(){ mentionRows().forEach((r,i)=>{ if(i===mentionIndex) r.setAttribute('active',''); else r.removeAttribute('active'); }); }

function currentMentionQuery(text, caret){
  const left = String(text||'').slice(0, caret);
  const m = left.match(/(^|\s)@([^\s@]{0,30})$/);
  return m ? (m[2]||'') : null;
}

function onlineUsers(){ try{ return sortUsersForList().filter(u => isOnline(u.id) && u.id !== ME_ID); }catch(_){ return []; } }

function buildMentionList(q){
  const ql = String(q||'').toLowerCase();
  let list = onlineUsers();
  if (ql) list = list.filter(u => (u.name||'').toLowerCase().startsWith(ql));
  return list.slice(0, 8);
}

function renderMentionBox(q){
  const items = buildMentionList(q);
  if (!items.length) { hideMentionBox(); return; }
  mentionBox.innerHTML = items.map(u => `
    <div class="row" data-id="${u.id}" data-name="${escAttr(u.name)}">
      <div class="nm">${esc(u.name)}</div>
    </div>`
  ).join('');
  mentionIndex = 0; highlightMentionRow(); showMentionBox();
}

function insertMention(name){
  if (!pubTA) return;
  const i   = pubTA.selectionStart || 0;
  const txt = pubTA.value || '';
  const left = txt.slice(0, i), right = txt.slice(i);
  const atPos = left.lastIndexOf('@'); if (atPos < 0) return;
  const before = left.slice(0, atPos);
  const newLeft = before + '@' + name + ' ';
  pubTA.value = newLeft + right;
  const pos = newLeft.length; pubTA.setSelectionRange(pos, pos);
  hideMentionBox();
}

pubTA.addEventListener('input', () => {
  const q = currentMentionQuery(pubTA.value, pubTA.selectionStart||0);
  if (q === null) { hideMentionBox(); return; }
  renderMentionBox(q);
});

pubTA.addEventListener('keydown', (e) => {
  if (!mentionBox || !mentionBox.hasAttribute('open')) return;
  if (e.key === 'ArrowDown' || e.key === 'Down') { mentionIndex = Math.min(mentionIndex + 1, mentionRows().length - 1); highlightMentionRow(); e.preventDefault(); }
  else if (e.key === 'ArrowUp' || e.key === 'Up') { mentionIndex = Math.max(mentionIndex - 1, 0); highlightMentionRow(); e.preventDefault(); }
  else if (e.key === 'Enter' || e.key === 'Tab') { const row = mentionRows()[mentionIndex]; if (row) insertMention(row.dataset.name||''); e.preventDefault(); }
  else if (e.key === 'Escape') { hideMentionBox(); }
});

mentionBox.addEventListener('click', (e)=>{
  const row = e.target.closest('.row'); if (!row) return;
  insertMention(row.dataset.name||'');
});

document.addEventListener('click', (e)=>{
  if (!mentionBox || !mentionBox.hasAttribute('open')) return;
  if (!mentionBox.contains(e.target) && !pubTA.contains(e.target)) hideMentionBox();
});

pubForm.addEventListener('submit', hideMentionBox);
(document.getElementById('kk-pubImg')||{}).addEventListener?.('change', hideMentionBox);

  function bindEnterToSend(textarea, form){
    if (!textarea || !form) return;
    textarea.setAttribute('enterkeyhint','send'); 
    textarea.addEventListener('keydown', (e)=>{
      if (e.isComposing || e.keyCode === 229) return; 
      const isPlainEnter = e.key === 'Enter' && !e.shiftKey && !e.ctrlKey && !e.altKey && !e.metaKey;
      if (!isPlainEnter) return;
      e.preventDefault(); 
      if (form.requestSubmit) form.requestSubmit();
      else form.querySelector('button[type="submit"],button:not([type])')?.click();
    });
  }

  bindEnterToSend(pubTA, pubForm);

let __camStream = null;
let __camFacing  = 'user'; // 'user' | 'environment' (not all desktops support)

async function openWebcamModal(){
  await closeWebcamModal(); // safety
  const constraints = {
    video: {
      facingMode: __camFacing,
      width: { ideal: 1280 },
      height:{ ideal: 720 }
    },
    audio: false
  };
  __camStream = await navigator.mediaDevices.getUserMedia(constraints);
  camVideo.srcObject = __camStream;
  camModal?.setAttribute('open','');
  document.documentElement.classList.add('kkchat-no-scroll');
}

async function closeWebcamModal(){
  try{
    if (__camStream){
      __camStream.getTracks().forEach(t => { try{ t.stop(); }catch(_){ } });
    }
  }catch(_){}
  __camStream = null;
  if (camVideo) camVideo.srcObject = null;
  camModal?.removeAttribute('open');
  document.documentElement.classList.remove('kkchat-no-scroll');
}

// Capture frame to blob -> upload -> send
async function takeWebcamPhoto(){
  if (!camVideo || !__camStream) return;
  if (camShot) camShot.disabled = true;
  let success = false;

  try{
    const v = camVideo;

    const canvas = document.createElement('canvas');
    const w = v.videoWidth || 1280;
    const h = v.videoHeight || 720;
    canvas.width = w; canvas.height = h;
    const ctx = canvas.getContext('2d');
    ctx.drawImage(v, 0, 0, w, h);

    const blob = await new Promise(res => canvas.toBlob(res, 'image/jpeg', 0.9));
    if (!blob) { showToast('Kunde inte ta bild'); return; }

    const file = new File([blob], 'camera.jpg', { type: 'image/jpeg' });

    success = await handleImageSend(file);
    if (success) {
      await closeWebcamModal();
    }
  } catch(e){
    showToast(e?.message || 'Uppladdning misslyckades');
  } finally {
    if (camShot) camShot.disabled = false;
  }

  return success;
}

// Controls
camShot?.addEventListener('click', async ()=>{
  await takeWebcamPhoto();
});
camCancel?.addEventListener('click', closeWebcamModal);

// Flip camera (where supported; mostly mobile/tablets with multiple cameras)
camFlip?.addEventListener('click', async ()=>{
  __camFacing = (__camFacing === 'user') ? 'environment' : 'user';
  try{
    await openWebcamModal(); // re-open with new constraint
  }catch(_){
    showToast('Kunde inte byta kamera');
  }
});

// Close on backdrop click
camModal?.addEventListener('click', (e)=>{
  if (e.target === camModal) closeWebcamModal();
});
document.addEventListener('keydown', (e)=>{
  if (e.key === 'Escape' && camModal?.hasAttribute('open')) closeWebcamModal();
});

function closeExplicitModal(result=null){
  if (explicitModal) explicitModal.removeAttribute('open');
  if (explicitResolver) { explicitResolver(result); explicitResolver = null; }
}

function promptExplicitChoice(file){
  if (!explicitModal || !explicitForm) return Promise.resolve(true);
  if (explicitName) explicitName.textContent = file?.name || 'Bilden';
  if (explicitCheck) explicitCheck.checked = true;
  explicitModal.setAttribute('open', '');
  requestAnimationFrame(()=>{ explicitCheck?.focus(); });
  return new Promise(resolve => { explicitResolver = resolve; });
}

explicitForm?.addEventListener('submit', (e)=>{
  e.preventDefault();
  closeExplicitModal(!!explicitCheck?.checked);
});
explicitCancel?.addEventListener('click', (e)=>{ e.preventDefault(); closeExplicitModal(null); });
explicitModal?.addEventListener('click', (e)=>{ if (e.target === explicitModal) closeExplicitModal(null); });
document.addEventListener('keydown', (e)=>{
  if (e.key === 'Escape' && explicitModal?.hasAttribute('open')) closeExplicitModal(null);
});


function appendPendingMessage(text){
  const li = document.createElement('li');
  li.className = 'item me';
  li.dataset.temp = String(Date.now());
  li.dataset.retryAttempts = '0';
  if (typeof ME_ID !== 'undefined') {
    li.dataset.sid = String(ME_ID || 0);
  }
  if (typeof ME_NM !== 'undefined') {
    li.dataset.sname = ME_NM || '';
  }
  li.dataset.kind = 'chat';
  li.dataset.body = text;

  const reply = COMPOSER_REPLY && Number.isFinite(Number(COMPOSER_REPLY.id))
    ? {
        id: Number(COMPOSER_REPLY.id),
        name: COMPOSER_REPLY.name || '',
        excerpt: COMPOSER_REPLY.excerpt || ''
      }
    : null;

  if (reply && reply.id > 0) {
    li.dataset.replyId = String(reply.id);
    li.dataset.replyName = reply.name || '';
    li.dataset.replyExcerpt = reply.excerpt || '';
  } else {
    delete li.dataset.replyId;
    delete li.dataset.replyName;
    delete li.dataset.replyExcerpt;
  }

  const now = new Date();
  const when = now.toLocaleTimeString('sv-SE', { hour: '2-digit', minute: '2-digit' });
  const who = typeof ME_NM !== 'undefined' ? ME_NM || '' : '';
  const gender = (typeof genderById === 'function' && typeof ME_ID !== 'undefined')
    ? genderById(ME_ID)
    : '';
  const genderMarkup = (typeof genderIconMarkup === 'function')
    ? genderIconMarkup(gender)
    : '';
  const nameMarkup = (who && typeof ME_NM !== 'undefined' && who === ME_NM)
    ? ''
    : esc(who);

  const replyPreviewHTML = reply ? replyPreviewMarkup(reply.id, reply.name, reply.excerpt) : '';

  li.innerHTML = `
    <button type="button" class="retry-btn" data-retry title="Försök igen" aria-label="Försök skicka igen">↻</button>
    <div class="bubble">${replyPreviewHTML}<div class="bubble-text">${esc(text)}</div></div>
    <div class="bubble-meta small">${genderMarkup}<span class="bubble-meta-text">${nameMarkup}<br>${esc(when)}</span></div>
  `;

  const list = document.getElementById('kk-pubList');
  if (list) {
    list.appendChild(li);
    try {
      if (typeof AUTO_SCROLL !== 'undefined' && (AUTO_SCROLL || atBottom(list))) {
        scrollToBottom(list, false);
      }
    } catch(_){}
  }
  return li;
}

function finalizePendingMessage(pending, payload){
  if (!pending) return;

  pending.classList.remove('error');

  const retryBtn = pending.querySelector('button[data-retry]');
  if (retryBtn) retryBtn.remove();

  delete pending.dataset.retryAttempts;
  delete pending.dataset.retryError;
  delete pending.dataset.retryPayload;
  delete pending.dataset.temp;

  const mid = Number(payload?.id ?? payload);
  if (Number.isFinite(mid) && mid > 0) {
    pending.dataset.id = String(mid);

    const currentLast = Number(pubList?.dataset?.last ?? -1);
    if (!Number.isFinite(currentLast) || mid > currentLast) {
      pubList.dataset.last = String(mid);
    }

    let replyId = null;
    let replyName = '';
    let replyExcerpt = '';

    if (payload && typeof payload === 'object') {
      replyId = Number(payload.reply_to_id || 0) > 0 ? Number(payload.reply_to_id) : null;
      replyName = payload.reply_to_sender_name || '';
      replyExcerpt = payload.reply_to_excerpt || '';
      const bubble = pending.querySelector('.bubble');
      if (replyId) {
        pending.dataset.replyId = String(replyId);
        pending.dataset.replyName = replyName || '';
        pending.dataset.replyExcerpt = replyExcerpt || '';
        if (bubble) {
          const existing = bubble.querySelector('.bubble-reply-preview');
          const markup = replyPreviewMarkup(replyId, replyName, replyExcerpt);
          if (existing) {
            existing.outerHTML = markup;
          } else {
            bubble.insertAdjacentHTML('afterbegin', markup);
          }
        }
      } else {
        delete pending.dataset.replyId;
        delete pending.dataset.replyName;
        delete pending.dataset.replyExcerpt;
        if (bubble) {
          const existing = bubble.querySelector('.bubble-reply-preview');
          existing?.remove();
        }
      }
    }

    rememberMessageMeta({
      id: mid,
      kind: pending.dataset.kind || 'chat',
      sender_id: Number(pending.dataset.sid || ME_ID || 0),
      sender_name: pending.dataset.sname || '',
      content: pending.dataset.body || '',
      reply_to_id: replyId,
      reply_to_sender_name: replyName,
      reply_to_excerpt: replyExcerpt,
    });

    const bubbleMeta = pending.querySelector('.bubble-meta-text');
    const serverTime = Number(payload?.time ?? 0);
    if (bubbleMeta && Number.isFinite(serverTime) && serverTime > 0) {
      const when = new Date(serverTime * 1000).toLocaleTimeString('sv-SE', { hour: '2-digit', minute: '2-digit' });
      const parts = bubbleMeta.innerHTML.split('<br>');
      if (parts.length === 2) {
        parts[1] = esc(when);
        bubbleMeta.innerHTML = parts.join('<br>');
      }
    }

    try {
      writeCache(activeCacheKey(), {
        last: +pubList.dataset.last || -1,
        html: pubList.innerHTML
      });
    } catch(_) {}
  }
}

const MAX_MESSAGE_ATTEMPTS = 3;
const MESSAGE_RETRY_DELAY_MS = 3000;

function serializeFormData(fd){
  const out = [];
  for (const [key, value] of fd.entries()) {
    if (key === 'csrf_token') continue;
    if (typeof value === 'string') {
      out.push([key, value]);
    }
  }
  return out;
}

function buildMessageFormData(entries){
  const fd = new FormData();
  for (const [key, value] of entries) {
    fd.append(key, value);
  }
  fd.append('csrf_token', CSRF);
  return fd;
}

async function sendMessageWithRetry(pending, entries, { resetOnSuccess = false } = {}){
  if (!pending) return false;
  pending.classList.remove('error');
  let lastErrorMsg = 'Tekniskt fel';

  for (let attempt = 1; attempt <= MAX_MESSAGE_ATTEMPTS; attempt++) {
    console.info(`KKchat: trying to send message (attempt ${attempt}/${MAX_MESSAGE_ATTEMPTS})`);
    const attemptFd = buildMessageFormData(entries);
    try {
      const r  = await fetch(API + '/message', { method:'POST', body: attemptFd, credentials:'include', headers:h });
      const js = await r.json().catch(()=>({}));

      if (r.ok && js.ok) {
        if (js.deduped) {
          console.info(`KKchat: duplicate message detected on attempt ${attempt}; removing pending bubble.`);
          pending.remove();
          showToast('Spam - Ditt meddelande avvisades.');
          clearComposerReply();
          return true;
        }

        if (resetOnSuccess) {
          pubForm.reset();
        }

        finalizePendingMessage(pending, js);
        clearComposerReply();
        await pollActive();
        console.info(`KKchat: message posted successfully on attempt ${attempt}.`);
        return true;
      }

           if (js.err === 'auto_moderated') {
        lastErrorMsg = '';
      } else {
        lastErrorMsg = js.err === 'no_room_access'
          ? 'Endast för medlemmar'
          : (js.cause || 'Kunde inte skicka');
      }
      console.warn(`KKchat: message attempt ${attempt} failed: ${lastErrorMsg}`);
    } catch(err) {
      lastErrorMsg = 'Tekniskt fel';
      console.error(`KKchat: message attempt ${attempt} encountered an error`, err);
    }

    if (attempt < MAX_MESSAGE_ATTEMPTS) {
      console.info(`KKchat: retrying message in ${Math.round(MESSAGE_RETRY_DELAY_MS / 1000)}s (next attempt ${attempt + 1}/${MAX_MESSAGE_ATTEMPTS}).`);
      try {
        await new Promise(res => setTimeout(res, MESSAGE_RETRY_DELAY_MS));
      } catch (_) {}
      continue;
    }

    pending.classList.add('error');
    pending.dataset.retryAttempts = String(attempt);
    pending.dataset.retryError = lastErrorMsg;
    if (lastErrorMsg) showToast(lastErrorMsg);
    return false;
  }

  return false;
}

replyCancel?.addEventListener('click', (e)=>{
  e.preventDefault();
  clearComposerReply();
});

pubForm.addEventListener('submit', async (e)=>{
  e.preventDefault();

  const fd = new FormData(pubForm);
  fd.append('csrf_token', CSRF);

  const txt = (fd.get('content')||'').toString().trim();
  if (!txt) return;

  if (currentDM) fd.append('recipient_id', String(currentDM));
  else fd.append('room', currentRoom);

  const entries = serializeFormData(fd);
  const pending = appendPendingMessage(txt);
  
  if (pending) {
    try {
      pending.dataset.retryPayload = JSON.stringify(entries);
    } catch(_){}
  }

  await sendMessageWithRetry(pending, entries, { resetOnSuccess: true });
});

pubList?.addEventListener('click', async (e)=>{
  const btn = e.target.closest('button[data-retry]');
  if (!btn) return;

  const li = btn.closest('li.item.me');
  if (!li) return;

  const payload = li.dataset.retryPayload;
  if (!payload) return;

  let entries;
  try {
    entries = JSON.parse(payload);
  } catch(_) {
    return;
  }

  btn.disabled = true;
  try {
    await sendMessageWithRetry(li, entries, { resetOnSuccess: false });
  } finally {
    if (btn.isConnected) {
      btn.disabled = false;
    }
  }
});

// ---- Image limits & compression settings ----
const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;     // server cap unchanged
const TARGET_MAX_BYTES = 3.2 * 1024 * 1024;   // smaller target payload
const MAX_DIM_PX       = 1280;                // downscale a bit more
const JPEG_QUALITY_INIT= 0.80;                // slightly lower default
const JPEG_QUALITY_MIN = 0.6;

function validateImageFileBasics(file){
  if (!file) return 'Ingen fil vald';
  if (!/^image\//.test(file.type || '')) return 'Endast bildfiler tillåtna';
  return '';
}

// Load an image for canvas (respects EXIF orientation where supported)
async function loadImageBitmap(src){
  // Try createImageBitmap (handles orientation with option)
  if (('createImageBitmap' in window)) {
    try {
      return await createImageBitmap(src, { imageOrientation: 'from-image' });
    } catch(_){}
  }
  // Fallback via <img>
  const blobUrl = URL.createObjectURL(src);
  try {
    const img = await new Promise((resolve, reject)=>{
      const el = new Image();
      el.onload = ()=> resolve(el);
      el.onerror = reject;
      el.src = blobUrl;
    });
    return img;
  } finally {
    URL.revokeObjectURL(blobUrl);
  }
}

function computeTargetSize(w, h, maxSide){
  if (w <= maxSide && h <= maxSide) return { w, h };
  const scale = (w > h) ? (maxSide / w) : (maxSide / h);
  return { w: Math.round(w * scale), h: Math.round(h * scale) };
}

async function canvasToSizedBlob(canvas, mime='image/jpeg', targetBytes=TARGET_MAX_BYTES, qStart=JPEG_QUALITY_INIT, qMin=JPEG_QUALITY_MIN){
  let q = qStart;
  let blob = await new Promise(res => canvas.toBlob(res, mime, q));
  // If still too big, reduce quality stepwise
  while (blob && blob.size > targetBytes && q > qMin){
    q = Math.max(qMin, q - 0.08);
    blob = await new Promise(res => canvas.toBlob(res, mime, q));
  }
  return blob;
}

/**
 * Compress/convert image to fit under TARGET_MAX_BYTES and MAX_DIM_PX.
 * - Converts HEIC/HEIF (and anything else) to JPEG.
 * - Downscales large dimensions and reduces quality if needed.
 * Returns a File ready for upload.
 */
async function compressImageIfNeeded(file){
  const basicErr = validateImageFileBasics(file);
  if (basicErr) throw new Error(basicErr);

  const isHeic = /image\/hei[cf]/i.test(file.type || '');
  const overCap = file.size > MAX_UPLOAD_BYTES;

  // If it's HEIC or too large, force compression/convert; else lightly check size
  if (!isHeic && !overCap) {
    // Still consider downsizing huge megapixels (saves bandwidth)
    // Quick probe: if one side exceeds MAX_DIM_PX, compress anyway
    try {
      const ib = await loadImageBitmap(file);
      const w = ib.videoWidth || ib.naturalWidth || ib.width || 0;
      const h = ib.videoHeight || ib.naturalHeight || ib.height || 0;
      if (Math.max(w, h) <= MAX_DIM_PX) {
        return file; // keep original
      }
      // else fall through to compress path
    } catch(_) {
      // If probe fails, just upload original (will pass server if <= 5MB)
      return file.size <= MAX_UPLOAD_BYTES ? file : file; // compress below
    }
  }

  // Do actual decode → draw → compress
  const img = await loadImageBitmap(file);
  const srcW = img.videoWidth || img.naturalWidth || img.width;
  const srcH = img.videoHeight || img.naturalHeight || img.height;
  const { w, h } = computeTargetSize(srcW, srcH, MAX_DIM_PX);

  const canvas = document.createElement('canvas');
  canvas.width = w; canvas.height = h;
  const ctx = canvas.getContext('2d');
  ctx.drawImage(img, 0, 0, w, h);

  const blob = await canvasToSizedBlob(canvas, 'image/jpeg', TARGET_MAX_BYTES, JPEG_QUALITY_INIT, JPEG_QUALITY_MIN);
  if (!blob) throw new Error('Kunde inte komprimera bild');

  // Final guard: still too big? (edge cases) – do a smaller resize pass
  if (blob.size > MAX_UPLOAD_BYTES) {
    const shrinkCanvas = document.createElement('canvas');
    const scale = Math.sqrt(MAX_UPLOAD_BYTES / blob.size) * 0.95; // heuristic
    const w2 = Math.max(640, Math.floor(w * scale));
    const h2 = Math.max(480, Math.floor(h * scale));
    shrinkCanvas.width = w2; shrinkCanvas.height = h2;
    const sctx = shrinkCanvas.getContext('2d');
    sctx.drawImage(canvas, 0, 0, w2, h2);
    const blob2 = await canvasToSizedBlob(shrinkCanvas, 'image/jpeg', TARGET_MAX_BYTES, Math.min(JPEG_QUALITY_INIT, 0.8), JPEG_QUALITY_MIN);
    if (!blob2 || blob2.size > MAX_UPLOAD_BYTES) throw new Error('Bilden är för stor (efter komprimering)');
    return new File([blob2], (file.name || 'upload') + '.jpg', { type: 'image/jpeg' });
  }

  return new File([blob], (file.name || 'upload') + '.jpg', { type: 'image/jpeg' });
}


async function uploadImage(file){
  const err = validateImageFileBasics(file); // ← was validateImageFile(...)
  if (err){ showToast(err); throw new Error(err); }

  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('file', file, file.name || 'upload.jpg');

  const r  = await fetch(API + '/upload', { method:'POST', body: fd, credentials:'include', headers:h });
  const js = await r.json().catch(()=>({}));
  if (!r.ok || !js.ok) {
    const msg = js.err || 'Uppladdning misslyckades';
    showToast(msg);
    throw new Error(msg);
  }
  return js.url;
}
  async function sendImageMessage(url, isExplicit = false){
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('kind', 'image');
    fd.append('image_url', url);
    fd.append('is_explicit', isExplicit ? '1' : '0');
    if (COMPOSER_REPLY && Number.isFinite(Number(COMPOSER_REPLY.id))) {
      fd.append('reply_to_id', String(COMPOSER_REPLY.id));
      fd.append('reply_excerpt', COMPOSER_REPLY.excerpt || '');
    }
    if (currentDM) fd.append('recipient_id', String(currentDM));
    else fd.append('room', currentRoom);
    const r  = await fetch(API + '/message', { method:'POST', body: fd, credentials:'include', headers:h });
    const js = await r.json().catch(()=>({}));
    if (!r.ok || !js.ok) { showToast(js.cause || js.err || 'Kunde inte skicka bild'); return false; }
    if (js.deduped) { showToast('Spam - Duplicerad bild avvisades.'); return false; }
    return true;
  }

  async function handleImageSend(file){
    if (!file) return false;
    const choice = await promptExplicitChoice(file);
    if (choice === null || choice === undefined) return false;

    try {
      showToast('Komprimerar…');
      const small = await compressImageIfNeeded(file);
      showToast('Laddar bild…');
      const url = await uploadImage(small);
      const ok  = await sendImageMessage(url, !!choice);
      if (ok) {
        await pollActive();
        showToast('Bild skickad');
        clearComposerReply();
      }
      return ok;
    } catch(e){
      showToast(e?.message || 'Uppladdning misslyckades');
      return false;
    }
  }

// Upload picker
pubUpBtn?.addEventListener('click', () => {
  closeAttachmentMenu();
  pubImgInp?.click();
});

pubImgInp?.addEventListener('change', async ()=>{
  const file = pubImgInp.files?.[0]; pubImgInp.value='';
  if (!file) return;
  await handleImageSend(file);
});


// Camera button
pubCamBtn?.addEventListener('click', async ()=>{
  closeAttachmentMenu();
  // Prefer native capture on mobile
  if (isMobileLike() && pubCamInp) {
    pubCamInp.click();
    return;
  }
  // Desktop (or mobile without capture): open webcam modal
  if (hasMediaDevices()) {
    openWebcamModal().catch(()=> showToast('Kunde inte öppna kamera'));
  } else {
    // Fallback to file picker if no webcam
    pubImgInp?.click();
  }
});

// Mobile capture <input capture="environment">
pubCamInp?.addEventListener('change', async ()=>{
  const file = pubCamInp.files?.[0]; pubCamInp.value='';
  if (!file) return;
  await handleImageSend(file);
});


function openImagePreview(src, alt, sid = null, sname = ''){
  try{
    const s = String(src || '');
    const a = String(alt || '');

    imgView.src = s;
    imgView.alt = a;
    imgCap.textContent = a;

    imgOpen.removeAttribute('href');
    imgOpen.style.display = 'none';
    try{
      const u = new URL(s, location.origin);
      if (u.protocol === 'http:' || u.protocol === 'https:') {
        imgOpen.href = u.href;
        imgOpen.style.display = '';
      }
    }catch(_){}

    const validTarget = !!sid && sid !== ME_ID;
    if (validTarget){
      MSG_TARGET = { id: sid, name: sname || nameById(sid) || ('#'+sid) };

      const blocked = isBlocked(sid);
      const blockLabel = blocked ? `${iconMarkup('task_alt')} Avblockera` : `${iconMarkup('block')} Blockera`;
      imgActBlock.innerHTML = blockLabel;

      imgActDm.style.display     = '';
      imgActReport.style.display = '';
      imgActBlock.style.display  = '';
      imgActBlock.disabled = false;
    } else {

      imgActDm.style.display     = 'none';
      imgActReport.style.display = 'none';
      imgActBlock.style.display  = 'none';
    }

    imgPanel.setAttribute('open','');
  }catch(_){}
}

  function closeImagePreview(){
    imgPanel.removeAttribute('open'); imgView.src=''; imgView.alt='';
  }
  imgClose?.addEventListener('click', closeImagePreview);
  imgPanel?.addEventListener('click', (e)=>{ if (e.target === imgPanel) closeImagePreview(); });
  document.addEventListener('keydown', (e)=>{ if (e.key==='Escape' && imgPanel.hasAttribute('open')) closeImagePreview(); });

  function bindPreview(listEl){
  listEl.addEventListener('click', (e)=>{
    const img = e.target.closest('img.imgmsg'); if (!img) return;
    const li    = img.closest('li.item');
    const sid   = Number(li?.dataset.sid || 0);
    const sname = li?.dataset.sname || '';
    const isExplicit = img.dataset.explicit === '1';
    if (IMG_BLUR && isExplicit && !img.dataset.unblurred) {
      img.dataset.unblurred = '1';
      return;
    }
    openImagePreview(img.currentSrc || img.src, img.alt || '', sid, sname);
  });
}

  bindPreview(pubList);

  function autoGrow(t){ t.style.height='auto'; t.style.height = Math.min(120, t.scrollHeight) + 'px'; }
  [...document.querySelectorAll('textarea')].forEach(t=>{ t.addEventListener('input', ()=>autoGrow(t)); setTimeout(()=>autoGrow(t),0); });

  pubList.addEventListener('scroll', ()=>{ maybeToggleFab(); markVisible(pubList); });
jumpBtn.addEventListener('click', ()=>{
  scrollToBottom(pubList, false); 
  maybeToggleFab();
});

  function bindCopy(el){
    let timer=null;
    el.addEventListener('touchstart', e=>{
      const bubble=e.target.closest('.bubble'); if(!bubble) return;
      const img = bubble.querySelector('img.imgmsg');
      const text = img ? img.src : (bubble.textContent||'');
      timer=setTimeout(()=>{ navigator.clipboard?.writeText(text); showToast('Kopierat'); }, 500);
    }, {passive:true});
    el.addEventListener('touchend', ()=>{ clearTimeout(timer); });
    el.addEventListener('contextmenu', e=>{
      const bubble=e.target.closest('.bubble'); if(!bubble) return; e.preventDefault();
      const img = bubble.querySelector('img.imgmsg');
      const text = img ? img.src : (bubble.textContent||'');
      navigator.clipboard?.writeText(text); showToast('Kopierat');
    });
  }
  bindCopy(pubList);

(function(){
  async function touch(){
    try {
      logDbActivity('sending activity ping');
      await fetch(API + '/ping', { credentials:'include', headers:h });
    } catch(_) {
      console.warn('KKchat: activity ping failed');
    }
  }

  touch();

  document.addEventListener('visibilitychange', () => { if (!document.hidden) touch(); });
  window.addEventListener('focus',  touch);
  window.addEventListener('online', touch);

  let pingTimer = null;

  let pollFallbackTimer = null;
  let pollFallbackBusy  = false;

  function maybePollActiveFallback(){
    if (pollFallbackBusy) return;
    if (!isInteractive()) return;

    pollFallbackBusy = true;
    pollActive().catch(()=>{}).finally(()=>{ pollFallbackBusy = false; });
  }

  function ensurePollFallback(){
    if (pollFallbackTimer) return;
    pollFallbackTimer = setInterval(maybePollActiveFallback, 45000);
    setTimeout(maybePollActiveFallback, 1000);
  }

  ensurePollFallback();

  function schedulePingFallback(){
    clearInterval(pingTimer);
    pingTimer = setInterval(()=>{
      touch();
      if (!isInteractive()) return;
      if (POLL_IS_LEADER) {
        maybePollActiveFallback();
      }
    }, 120000); // 2 minutes
  }

  schedulePingFallback();
  document.addEventListener('visibilitychange', schedulePingFallback);
  window.addEventListener('online', schedulePingFallback);
  window.addEventListener('focus', schedulePingFallback);

})();

  let LOG_USER_ID = null;
  let LOG_BEFORE  = 0;
  let LOG_BUSY    = false;

  const logPanel = document.getElementById('kk-logPanel');
  const logUser  = document.getElementById('kk-logUser');
  const logList  = document.getElementById('kk-logList');
  const logMore  = document.getElementById('kk-logMore');
  const logClose = document.getElementById('kk-logClose');

  function openLogs(uid){
    if (!IS_ADMIN) return;
    LOG_USER_ID = uid;
    LOG_BEFORE  = 0;
    logList.innerHTML = '';
    logUser.textContent = nameById(uid);
    logPanel?.setAttribute('open','');
    loadMoreLogs();
  }
  function closeLogs(){
    logPanel?.removeAttribute('open');
    LOG_USER_ID = null;
    LOG_BEFORE  = 0;
  }
  logClose?.addEventListener('click', closeLogs);
  logPanel?.addEventListener('click', (e)=>{ if (e.target === logPanel) closeLogs(); });

  function fmtWhen(ts){
    try{ return new Date(ts*1000).toLocaleString('sv-SE'); }catch(_){ return String(ts); }
  }
    function playReportOnce(){
    try{
      if (typeof soundsMuted === 'function' && soundsMuted()) return;
      const el = document.getElementById('kk-reportSound');
      if (!el) return;
      el.currentTime = 0;
      el.play()?.catch(()=>{});
      if (typeof muteFor === 'function') muteFor(800);
    }catch(_){}
  }

  function renderReports(rows){
    if (!reportListEl) return;
    const html = (Array.isArray(rows)?rows:[]).map(r => {
      const ts  = Number(r.created_at||0);
      const id  = Number(r.id||0);
      const rep = `${String(r.reporter_name||'')} (${Number(r.reporter_id||0)})`;
      const tgt = `${String(r.reported_name||'')} (${Number(r.reported_id||0)})`;
      const reason = String(r.reason||'').trim();
      return `
        <div class="user report" data-id="${id}">
          <div class="user-main">
            <b>#${id} • ${fmtWhen(ts)}</b>
            <div class="small">Reporter: ${esc(rep)} → Reported: ${esc(tgt)}</div>
            <div class="small">${esc(reason)}</div>
          </div>
          <div class="user-actions">
            <button class="modbtn" data-resolve="${id}" title="Resolve" aria-label="Markera som löst">${iconMarkup('task_alt')}</button>
            <button class="modbtn" data-delete="${id}"  title="Delete" aria-label="Ta bort rapport">${iconMarkup('delete')}</button>
          </div>
        </div>`;
    }).join('');
    reportListEl.innerHTML = html || '<div class="user"><div class="user-main"><div class="small">Inga öppna rapporter</div></div></div>';
  }

  async function loadReports(){
    if (!IS_ADMIN || !reportListEl) return;
    const js = await fetchJSON(`${API}/reports?status=open`);
    if (js && js.ok && Array.isArray(js.rows)) {
      renderReports(js.rows);
    }
  }

  reportRefreshBtn?.addEventListener('click', ()=>{ loadReports().catch(()=>{}); });

  reportListEl?.addEventListener('click', async (e)=>{
    const res = e.target.closest('[data-resolve]');
    if (res){
      const id = Number(res.getAttribute('data-resolve'));
      const fd = new FormData();
      fd.append('csrf_token', CSRF);
      fd.append('id', String(id));
      try{
        const r  = await fetch(`${API}/reports/resolve`, { method:'POST', body:fd, credentials:'include', headers:h });
        const js = await r.json().catch(()=>({}));
        if (r.ok && js.ok){
          reportListEl.querySelector(`.report[data-id="${id}"]`)?.remove();
          if (OPEN_REPORTS_COUNT > 0) { OPEN_REPORTS_COUNT--; updateLeftCounts(); }
        } else alert(js.err || 'Kunde inte åtgärda');
      }catch(_){ alert('Tekniskt fel'); }
      return;
    }

    const del = e.target.closest('[data-delete]');
    if (del){
      const id = Number(del.getAttribute('data-delete'));
      if (!confirm(`Radera rapport #${id}?`)) return;
      const fd = new FormData();
      fd.append('csrf_token', CSRF);
      fd.append('id', String(id));
      try{
        const r  = await fetch(`${API}/reports/delete`, { method:'POST', body:fd, credentials:'include', headers:h });
        const js = await r.json().catch(()=>({}));
        if (r.ok && js.ok){
          reportListEl.querySelector(`.report[data-id="${id}"]`)?.remove();
          if (OPEN_REPORTS_COUNT > 0) { OPEN_REPORTS_COUNT--; updateLeftCounts(); }
        } else alert(js.err || 'Kunde inte radera');
      }catch(_){ alert('Tekniskt fel'); }
    }
  });

  function dmOrRoom(m){
    if (m.recipient_id) return 'DM';
    return m.room ? `#${esc(m.room)}` : '—';
  }
 function renderLogRows(rows){
  const frag = document.createDocumentFragment();

  rows.forEach(m => {
    const li = document.createElement('li');
    li.className = 'logitem' + (m.hidden ? ' hidden' : '');
    if (m.id) li.dataset.id = String(m.id);

    const left = document.createElement('div');
    left.className = 'meta';
    left.innerHTML =
      `<div>${fmtWhen(m.time)}</div>
       <div>${dmOrRoom(m)}</div>
       <div>${esc(m.kind||'chat')}</div>
       <div>ID: ${m.id || '-'}</div>`;

    const right = document.createElement('div');

    const contentHTML = (m.kind||'chat') === 'image'
      ? `<img class="imgmsg" src="${escAttr(String(m.content || ''))}" alt="Bild" style="max-width:220px;max-height:160px;border:1px solid #e5e7eb;border-radius:8px;cursor:zoom-in">`
      : `<div style="white-space:pre-wrap">${esc(String(m.content || ''))}</div>`;

    const headMeta =
      `<div class="meta">
         <b>${esc(m.sender_name||'')}</b>
         <small>(${esc(m.sender_ip||'')})</small>
         &nbsp;→&nbsp;
         ${m.recipient_id
            ? `<b>${esc(m.recipient_name||('#'+m.recipient_id))}</b> <small>(${esc(m.recipient_ip||'')})</small>`
            : `<code>${esc(m.room||'public')}</code>`}
       </div>`;

    const isAdmin = (typeof IS_ADMIN !== 'undefined' && IS_ADMIN);
    let statusControls = '';
    if (isAdmin) {
      const badge = m.hidden
        ? `<span class="badge badge-hidden">Dolt</span>`
        : `<span class="badge badge-active">Synlig</span>`;

      const cause = m.hidden_cause
        ? `<small class="muted">(${esc(m.hidden_cause)})</small>`
        : '';

      const btnHide   = `<button class="modbtn" data-hide="${m.id}" ${m.hidden ? 'style="display:none"' : ''}>Dölj</button>`;
      const btnUnhide = `<button class="modbtn" data-unhide="${m.id}" ${m.hidden ? '' : 'style="display:none"'}>Återställ</button>`;

      statusControls =
        `<div class="meta">
           ${badge}
           ${btnHide}
           ${btnUnhide}
           ${cause}
         </div>`;
    }

    right.innerHTML = headMeta + statusControls + contentHTML;

    li.appendChild(left);
    li.appendChild(right);
    frag.appendChild(li);
  });

  logList.appendChild(frag);
}

logList?.addEventListener('click', async (e) => {
  const hideBtn   = e.target.closest('[data-hide]');
  const unhideBtn = e.target.closest('[data-unhide]');
  if (!hideBtn && !unhideBtn) return;

  const id = Number((hideBtn||unhideBtn).dataset.hide || (hideBtn||unhideBtn).dataset.unhide);
  if (!id) return;

  const fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('message_id', String(id));

  if (hideBtn) {
    const cause = prompt('Anledning (valfritt):', '') || '';
    if (cause) fd.append('cause', cause);
  }

  const url = hideBtn ? '/moderate/hide-message' : '/moderate/unhide-message';

  try {
    const r  = await fetch(API + url, { method:'POST', body: fd, credentials:'include', headers: h });
    const js = await r.json().catch(() => ({}));

    if (!(r.ok && js.ok)) {
      alert(js?.err || 'Kunde inte uppdatera');
      return;
    }

    const row = (hideBtn||unhideBtn).closest('li.logitem');
    if (!row) return;

    const badge = row.querySelector('.badge');
    const btnHideEl   = row.querySelector('[data-hide]');
    const btnUnhideEl = row.querySelector('[data-unhide]');

    if (hideBtn) {
      row.classList.add('hidden');
      if (badge) { badge.className = 'badge badge-hidden'; badge.textContent = 'Dolt'; }
      if (btnHideEl)   btnHideEl.style.display = 'none';
      if (btnUnhideEl) btnUnhideEl.style.display = '';

      const causeText = fd.get('cause');
      if (causeText && !row.querySelector('.muted')) {
        const meta = row.querySelector('.meta'); 
        if (meta) {
          const small = document.createElement('small');
          small.className = 'muted';
          small.textContent = `(${causeText})`;
          meta.appendChild(document.createTextNode(' '));
          meta.appendChild(small);
        }
      } else if (causeText) {
        row.querySelector('.muted').textContent = `(${causeText})`;
      }
      if (typeof showToast === 'function') showToast('Meddelandet dolt');
    } else {
      row.classList.remove('hidden');
      if (badge) { badge.className = 'badge badge-active'; badge.textContent = 'Synlig'; }
      if (btnUnhideEl) btnUnhideEl.style.display = 'none';
      if (btnHideEl)   btnHideEl.style.display = '';

      const causeEl = row.querySelector('.muted');
      if (causeEl) causeEl.remove();
      if (typeof showToast === 'function') showToast('Meddelandet återställt');
    }
  } catch (err) {
    alert('Nätverksfel');
  }
});

  async function loadMoreLogs(){
    if (!IS_ADMIN || !LOG_USER_ID || LOG_BUSY) return;
    LOG_BUSY = true;
    try{
      const js  = await fetchJSON(`${API}/admin/user-messages?user_id=${LOG_USER_ID}&limit=60${LOG_BEFORE?`&before_id=${LOG_BEFORE}`:''}`);
      if (!js || js.ok === false){ alert(js?.err || 'Kunde inte hämta loggar'); return; }
      const rows = js.rows || [];
      renderLogRows(rows);
      LOG_BEFORE = js.next_before || 0;
      if (logMore) logMore.style.display = LOG_BEFORE ? '' : 'none';
    }catch(_){ alert('Kunde inte hämta loggar'); }
    finally { LOG_BUSY = false; }
  }
  logMore?.addEventListener('click', loadMoreLogs);


// ===== Keep-alive ping (works in background) =================
const PING_URL = `${API}/ping`;

// Ping every ~55–63s. Background tabs throttle timers, but this cadence
// plus beacons on hide/close keep presence alive under typical 120s TTL.
const PING_BASE_MS = 85_000;
const PING_JITTER  = 15_000;

let keepAliveTimer = null;

initMultiTabLock();

window.addEventListener('beforeunload', () => {
  if (!MULTITAB_IS_ACTIVE) return;
  if (multiTabOwnerId !== POLL_CLIENT_ID) return;
  try {
    const rec = readActiveTabRecord();
    if (!rec || rec.id === POLL_CLIENT_ID) {
      localStorage.removeItem(MULTITAB_ACTIVE_KEY);
    }
  } catch (_) {}
});

function scheduleKeepAlive() {
  const delay = PING_BASE_MS + Math.floor(Math.random() * PING_JITTER);
  logDbActivity(`scheduling keep-alive ping in ${delay}ms`);
  keepAliveTimer = setTimeout(pingOnce, delay);
}

async function pingOnce() {
  logDbActivity('sending keep-alive ping');
  try {
    const r  = await fetch(`${PING_URL}?ts=${Date.now()}`, {
      method: 'GET',
      credentials: 'include',
      cache: 'no-store',
      keepalive: true,
    });
    const js = await r.json().catch(()=>null);

    logDbActivity(`keep-alive ping responded with status ${r?.status ?? 'n/a'}`);

    if (js && IS_ADMIN) {
      const openCnt = Number(js.reports_open || 0);
      const maxId   = Number(js.reports_max_id || 0);

      // Update badge count (no extra polling)
      OPEN_REPORTS_COUNT = Number.isFinite(openCnt) ? openCnt : 0;
      updateLeftCounts?.();

      // Rising-edge detection: ring only when a *new* report arrives
      // Avoid ringing on first ping when LAST_REPORT_MAX_ID is 0
      if (Number.isFinite(maxId)) {
        if (LAST_REPORT_MAX_ID > 0 && maxId > LAST_REPORT_MAX_ID) {
          playReportOnce();
        }
        LAST_REPORT_MAX_ID = Math.max(LAST_REPORT_MAX_ID || 0, maxId);
      }
    }
  } catch (_) {
    // ignore transient network errors
    console.warn('KKchat: keep-alive ping failed');
  } finally {
    scheduleKeepAlive();
  }
}

function startKeepAlive() {
  if (!keepAliveTimer) scheduleKeepAlive();
}

function stopKeepAlive() {
  if (keepAliveTimer) {
    clearTimeout(keepAliveTimer);
    keepAliveTimer = null;
  }
}

// POST beacon works reliably when the tab is hidden or closing.
// We allowed POST on /ping above.
function beaconPing() {
  const url = `${PING_URL}?ts=${Date.now()}`;
  if (navigator.sendBeacon) {
    const blob = new Blob(['{}'], { type: 'application/json' });
    navigator.sendBeacon(url, blob);
  } else {
    // Fallback (may be dropped by some browsers, but cheap to try)
    fetch(url, { method: 'GET', keepalive: true, credentials: 'include' }).catch(()=>{});
  }
}

// Push a beacon immediately when we go hidden; keep the loop running too
document.addEventListener('visibilitychange', () => {
  if (document.visibilityState === 'hidden') {
    beaconPing();
  } else {
    startKeepAlive(); // ensure loop restarts when visible again
  }
});

// Ensure a final ping when the page is being closed or frozen (BFCache)
window.addEventListener('pagehide', beaconPing, { capture: true });
window.addEventListener('beforeunload', beaconPing, { capture: true });
// Some browsers fire 'freeze' when putting a page in BFCache
window.addEventListener('freeze', beaconPing, { capture: true });

const cancelPollOnTeardown = () => {
  abortActivePoll();
  stopStream();
};

window.addEventListener('pagehide', cancelPollOnTeardown, { capture: true });
window.addEventListener('beforeunload', cancelPollOnTeardown, { capture: true });
window.addEventListener('freeze', cancelPollOnTeardown, { capture: true });

async function refreshUsersAndUnread(){
  try {
    await pollActive();
  } catch(_){}
}


async function init(){
  await multiTabReady;
  try{
    await Promise.all([
      refreshBlocked().catch(e => { console.warn('refreshBlocked failed', e); }),
      loadRooms().catch(e => { console.warn('loadRooms failed', e); })
    ]);
    renderDMSidebar();
    renderRoomTabs();
    const unreadPromise = refreshUsersAndUnread()
      .catch(e => { console.warn('refreshUsersAndUnread failed', e); });

    if (OPEN_DM_USER) {
      await openDM(OPEN_DM_USER);     // <-- await to avoid racing
    } else {
      const snapshotPromise = loadHistorySnapshot();
      const syncPromise = pollActive(true).catch(()=>{});          // single, awaited warm-up poll (force fresh)
      openStream();
      await snapshotPromise.catch(()=>{});
      await syncPromise;
    }
    await unreadPromise;
  } catch (e) {
    // optionally log e
  }

  maybeToggleFab();
}
init();

 document.addEventListener('visibilitychange', ()=>{
   if (document.visibilityState === 'visible') refreshUsersAndUnread().catch(()=>{});
 });

})();
</script>
<script>
(function(){
  const mq     = window.matchMedia('(max-width: 900px)');
  const side   = document.getElementById('kk-sidebar');
  const btn    = document.getElementById('kk-sideOpenBtn') || document.getElementById('kk-sideToggle');
  const wrap   = document.getElementById('kk-leftWrap');
  const overlay= document.getElementById('kkchat-sideOverlay');
  const sheet  = overlay ? overlay.querySelector('.side-sheet') : null;
  const content= document.getElementById('kk-sideContent');
  const closeBtn = document.getElementById('kk-sideClose');

  if (!side || !wrap || !overlay || !content || !sheet || !closeBtn) return;

  const ph = document.createElement('div');
  ph.id = 'kk-leftWrap-placeholder';
  ph.style.display = 'none';
  wrap.after(ph);

  let lastFocus = null;

  function lockScroll(on){
    document.documentElement.classList.toggle('kkchat-no-scroll', on);
    document.body.classList.toggle('kkchat-no-scroll', on);
  }
  function moveWrapToOverlay(){
    if (!content.contains(wrap)) content.appendChild(wrap);
  }
  function moveWrapBack(){
    if (ph.parentNode && wrap.parentNode !== ph.parentNode){
      ph.parentNode.insertBefore(wrap, ph.nextSibling);
    }
  }

  function onKeydown(e){ if (e.key === 'Escape') { e.preventDefault(); closeOverlay(); } }
  function trapFocus(e){
    if (!overlay.hasAttribute('open')) return;
    if (!overlay.contains(e.target)) sheet.focus();
  }

  function openOverlay(){
    if (!mq.matches) return; 
    lastFocus = document.activeElement;
    moveWrapToOverlay();
    overlay.setAttribute('open','');
    overlay.setAttribute('aria-hidden','false');
    if (btn) btn.setAttribute('aria-expanded','true');
    lockScroll(true);
    sheet.focus();
    document.addEventListener('keydown', onKeydown, true);
    document.addEventListener('focus',   trapFocus, true);
  }

  function closeOverlay(){
    overlay.removeAttribute('open');
    overlay.setAttribute('aria-hidden','true');
    if (btn) btn.setAttribute('aria-expanded','false');
    lockScroll(false);
    moveWrapBack();
    document.removeEventListener('keydown', onKeydown, true);
    document.removeEventListener('focus',   trapFocus, true);
    if (lastFocus && typeof lastFocus.focus === 'function') lastFocus.focus();
  }

  if (btn){
    btn.addEventListener('click', (e)=>{
      if (!mq.matches) return; 
      e.preventDefault();
      openOverlay();
    });
  }

  closeBtn.addEventListener('click', closeOverlay);
  overlay.addEventListener('click', (e)=>{ if (e.target === overlay) closeOverlay(); });

  wrap.addEventListener('click', (e)=>{
    if (!mq.matches) return;
    const shouldClose = e.target.closest('[data-dm],[data-open],[data-join],[data-leave]');
    if (shouldClose) closeOverlay();
  });

  function onMediaChange(){
    if (!mq.matches){

      closeOverlay();
      moveWrapBack();
    }
  }
  onMediaChange();
  if (mq.addEventListener) mq.addEventListener('change', onMediaChange);
  else mq.addListener(onMediaChange);
})();
</script>


<script>
(function(){
  const root     = document.getElementById('kkchat-root');
  const list     = root?.querySelector('.list');
  const inputBar = root?.querySelector('.inputbar');
  const vv       = window.visualViewport;

  function getKeyboardOffset(){
    if (!vv) return 0;
    return Math.max(0, (window.innerHeight - vv.height - vv.offsetTop));
  }

  function applyKbOffset(){
    if (!root || !inputBar) return;
    const kbOffset = getKeyboardOffset();
    document.documentElement.style.setProperty('--kb-offset', kbOffset + 'px');
    const composerHeight = inputBar.offsetHeight || 0;
    document.documentElement.style.setProperty('--composer-height', composerHeight + 'px');
    if (kbOffset > 0) {
      document.documentElement.classList.add('kb-open');
    } else {
      document.documentElement.classList.remove('kb-open');
    }
    if (list) {
      list.style.paddingBottom = '';
    }
  }
  if (vv){
    vv.addEventListener('resize', applyKbOffset);
    vv.addEventListener('scroll', applyKbOffset);
  }
  window.addEventListener('orientationchange', applyKbOffset);
  window.addEventListener('focusin', (e)=>{
    if (e.target && (e.target.tagName === 'TEXTAREA' || e.target.tagName === 'INPUT')){
      setTimeout(()=>{
        e.target.scrollIntoView({block:'center', inline:'nearest', behavior:'smooth'});
        applyKbOffset();
      }, 50);
    }
  });
  document.addEventListener('kkchat:composer-change', ()=>{
    requestAnimationFrame(applyKbOffset);
  });
  applyKbOffset();

  function jumpToBottom(el){
    if (!el) return;

    root?.classList.remove('smooth');
    requestAnimationFrame(()=>{
      el.scrollTop = el.scrollHeight;
      requestAnimationFrame(()=>{
        el.scrollTop = el.scrollHeight; 

        root?.classList.add('smooth');
      });
    });
  }

  if (list){

    jumpToBottom(list);
  }

  const tabButtons = document.querySelectorAll('[data-kkchat-tab], .kkchat-tab, .rooms a, .dms a');
  tabButtons.forEach(btn=>{
    btn.addEventListener('click', ()=>{

      setTimeout(()=> jumpToBottom(list), 0);
    });
  });

  let firstHydrate = true;
  if (list){
    const mo = new MutationObserver(()=>{
      if (firstHydrate){
        firstHydrate = false;
        jumpToBottom(list);
      }
    });
    mo.observe(list, { childList:true });
  }

  window.__kkchatScroll = { jumpToBottom };
})();
</script>