<?php
if (!defined('ABSPATH')) exit;

add_shortcode('kkchat', function () {
  $ns = esc_js( wp_make_link_relative( rest_url('kkchat/v1') ) );
  $csrf = esc_js($_SESSION['kkchat_csrf'] ?? '');
  $me_logged = isset($_SESSION['kkchat_user_id'], $_SESSION['kkchat_user_name']);
  if ($me_logged && empty($_SESSION['kkchat_seen_at_public'])) $_SESSION['kkchat_seen_at_public'] = time();

  $me_id = $me_logged ? (int)$_SESSION['kkchat_user_id'] : 0;
  $me_nm = $me_logged ? (string)$_SESSION['kkchat_user_name'] : '';
  $wp_user = wp_get_current_user();
  $wp_logged = $wp_user && !empty($wp_user->ID);
  $wp_username = $wp_logged ? $wp_user->user_login : '';
  if ($wp_logged) $_SESSION['kkchat_wp_username'] = $wp_username;

  $is_admin = !empty($_SESSION['kkchat_is_admin']) && kkchat_is_admin();

  $admin_links = [];
  if ($is_admin) {
    $admin_links = [
      ['text' => 'Rum',           'url' => admin_url('admin.php?page=kkchat_rooms')],
      ['text' => 'Banderoller',   'url' => admin_url('admin.php?page=kkchat_banners')],
      ['text' => 'Moderering',    'url' => admin_url('admin.php?page=kkchat_moderation')],
      ['text' => 'Ord',           'url' => admin_url('admin.php?page=kkchat_words')],
      ['text' => 'Loggar',        'url' => admin_url('admin.php?page=kkchat_admin_logs')],
      ['text' => 'Bildmoderering','url' => admin_url('admin.php?page=kkchat_media')],
      ['text' => 'Rapporter',     'url' => admin_url('admin.php?page=kkchat_reports')],
      ['text' => 'Inställningar', 'url' => admin_url('admin.php?page=kkchat_settings')],
    ];
  }

  $poll_hidden_threshold = max(0, (int) get_option('kkchat_poll_hidden_threshold', 90));
  $poll_hidden_delay     = max(0, (int) get_option('kkchat_poll_hidden_delay', 30));
  $poll_hot_interval     = max(1, (int) get_option('kkchat_poll_hot_interval', 4));
  $poll_medium_interval  = max($poll_hot_interval, (int) get_option('kkchat_poll_medium_interval', 8));
  $poll_slow_interval    = max($poll_medium_interval, (int) get_option('kkchat_poll_slow_interval', 16));
  $poll_medium_after     = max(0, (int) get_option('kkchat_poll_medium_after', 3));
  $poll_slow_after       = max($poll_medium_after, (int) get_option('kkchat_poll_slow_after', 5));
  $poll_extra_2g         = max(0, (int) get_option('kkchat_poll_extra_2g', 20));
  $poll_extra_3g         = max(0, (int) get_option('kkchat_poll_extra_3g', 10));

  $poll_settings = [
    'hiddenThresholdMs' => $poll_hidden_threshold * 1000,
    'hiddenDelayMs'     => $poll_hidden_delay * 1000,
    'hotIntervalMs'     => $poll_hot_interval * 1000,
    'mediumIntervalMs'  => $poll_medium_interval * 1000,
    'slowIntervalMs'    => $poll_slow_interval * 1000,
    'mediumAfterMs'     => $poll_medium_after * 60 * 1000,
    'slowAfterMs'       => $poll_slow_after * 60 * 1000,
    'extra2gMs'         => $poll_extra_2g * 1000,
    'extra3gMs'         => $poll_extra_3g * 1000,
  ];

  $rest_nonce = esc_js( wp_create_nonce('wp_rest') );
  $open_dm = isset($_GET['dm']) ? (int)$_GET['dm'] : 'null';

  $plugin_root_url = plugin_dir_url( __DIR__ . '/../kkchat.php' );
    $audio         = esc_url($plugin_root_url . 'assets/notification.mp3');
    $mention_audio = esc_url($plugin_root_url . 'assets/mention.mp3');
        $report_audio  = esc_url($plugin_root_url . 'assets/report.mp3'); // NEW file

    
  wp_enqueue_style('kkchat');
  ob_start(); ?>


  <div class="kk-wrap" id="kkchat-root">
  <?php if (!$me_logged): ?>
    <div class="login">
  <div class="kk-login-overlay" aria-hidden="true" style="display:none">
    <div class="kk-login-spinner" role="status" aria-label="Laddar"></div>
  </div>
      <?php if ($wp_logged): ?>
        <h1>Välkommen till KKchatten</h1>
        <p>Du är inloggad som <b><?= kkchat_html_esc($wp_username) ?></b>. Kön hämtas inte automatiskt. Vänligen fyll i ditt kön.</p>
        <form id="kk-loginForm" method="post">
          <input type="hidden" name="csrf_token" value="<?= kkchat_html_esc($_SESSION['kkchat_csrf']) ?>">
          <input type="hidden" name="via_wp" value="1">
          <label for="login_gender">Ange kön</label>
          <select id="login_gender" name="login_gender" required>
            <option value="" disabled selected>— Välj —</option>
            <option>Man</option><option>Woman</option><option>Couple</option>
            <option>Trans (MTF)</option><option>Trans (FTM)</option><option>Non-binary/other</option>
          </select>
                    <p class="kk-terms">
            <a href="<?= esc_url( home_url('/anvandarvillkor-kkchatt/') ) ?>" target="_blank" rel="noopener">Klicka här för att läsa våra användarvillkor</a>.
          </p>
          <label class="kk-agree">
            <input type="checkbox" id="login_agree" name="login_agree" required>
            Jag godkänner användarvillkoren
          </label>

          <button>Logga in i chatten</button>
          <div id="kk-loginErr" class="err" style="display:none"></div>
        </form>
      <?php else: ?>
        <h1>Välj ett smeknamn</h1>
        <p>Du kommer att visas som <b>namn-guest</b>. Namnet blir ledigt igen när du loggar ut eller blir inaktiv.</p>
        <form id="kk-loginForm" method="post">
          <input type="hidden" name="csrf_token" value="<?= kkchat_html_esc($_SESSION['kkchat_csrf']) ?>">
          <label for="login_nick">Smeknamn</label>
          <input id="login_nick" name="login_nick" maxlength="24" placeholder="t.ex. Alex" autocomplete="off" required>
          <label for="login_gender">Ange kön</label>
          <select id="login_gender" name="login_gender" required>
            <option value="" disabled selected>— Välj —</option>
            <option>Man</option><option>Woman</option><option>Couple</option>
            <option>Trans (MTF)</option><option>Trans (FTM)</option><option>Non-binary/other</option>
          </select>
                    <p class="kk-terms">
            <a href="<?= esc_url( home_url('/anvandarvillkor-kkchatt/') ) ?>" target="_blank" rel="noopener">Klicka här för att läsa våra användarvillkor</a>.          </p>
          <label class="kk-agree">
            <input type="checkbox" id="login_agree" name="login_agree" required>
            Jag godkänner användarvillkoren
          </label>

          <button>Logga in i chatten</button>
          <div id="kk-loginErr" class="err" style="display:none"></div>
        </form>
      <?php endif; ?>
    </div>
    <script>
    (function(){
      const API = "<?= $ns ?>";
      const REST_NONCE = "<?= $rest_nonce ?>";
      const f=document.getElementById('kk-loginForm');
      const e=document.getElementById('kk-loginErr');
      const loginBox = document.querySelector('.login');
        const overlay  = document.querySelector('.kk-login-overlay');
        function setSubmittingState(on){
          if (!loginBox) return;
          loginBox.classList.toggle('is-loading', !!on);
          if (overlay) overlay.style.display = on ? 'flex' : 'none';
          if (f && f.elements) { for (const el of f.elements) el.disabled = !!on; }
        }


    function jumpToBottom(list){
  if (!list) return;

  const root = document.getElementById('kkchat-root');
  root?.classList.remove('smooth');

  requestAnimationFrame(()=>{
    list.scrollTop = list.scrollHeight;
    requestAnimationFrame(()=>{
      list.scrollTop = list.scrollHeight;

      root?.classList.add('smooth');
    });
  });
}

f.addEventListener('submit', async (ev)=>{
  ev.preventDefault();
  const fd = new FormData(f);
  fd.append('csrf_token', "<?= $csrf ?>");

  // turn on loading UI and prevent double submits
  setSubmittingState(true);
  e.style.display = 'none';
  e.textContent = '';

  try{
    const r = await fetch(API+'/login', {
      method:'POST',
      body: fd,
      credentials:'include',
      headers:{'X-WP-Nonce': REST_NONCE}
    });
    const js = await r.json();

    if (js.ok) {
      // Successful: page will reload, no need to turn off loader
      location.reload();
      return;
    }

    // Error: show message and re-enable form
    e.textContent =
      js.err === 'ip_banned' ? 'Regelbrott - Bannad' :
      js.err === 'kicked'    ? 'Regelbrott - Kickad' :
      (js.err || 'Ogiltigt val');
    e.style.display = 'block';
    setSubmittingState(false);

  } catch(_){
    e.textContent = 'Tekniskt fel';
    e.style.display = 'block';
    setSubmittingState(false);
  }
});

    })();
    </script>
  <?php else: ?>
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
  🔎 Sök & personer
</button>

    <div class="kk-layout">
      <aside class="sidebar" id="kk-sidebar">

          <!-- Wrapped original content -->
          <div id="kk-leftWrap">
            <div class="lefttabs" id="kk-leftTabs">
  <button type="button" data-view="users" aria-selected="true">
    👤 <span class="badge" id="kk-countUsers">0</span>
  </button>
  <button type="button" data-view="rooms" aria-selected="false">
    💬 <span class="badge" id="kk-countRooms">0</span>
  </button>
  <button type="button" data-view="dms" aria-selected="false">
    📩 <span class="badge" id="kk-countDMs">0</span>
  </button>
    <?php if ($is_admin): ?>
  <button type="button" data-view="reports" aria-selected="false">
    🚩 <span class="badge" id="kk-countReports">0</span>
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
      <button id="kk-reportRefresh" type="button" class="iconbtn">🔄</button>
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
            <button id="kk-jumpBottom" class="fab" type="button" aria-label="Hoppa till botten">⬇️</button>
            <form id="kk-pubForm" class="inputbar">
              <input type="hidden" name="csrf_token" value="<?= kkchat_html_esc($_SESSION['kkchat_csrf']) ?>">
              <div class="input-actions" data-attach-root>
                <button
                  type="button"
                  class="iconbtn input-actions-toggle"
                  id="kk-attachToggle"
                  data-attach-toggle
                  title="Fler alternativ"
                  aria-haspopup="menu"
                  aria-expanded="false"
                >➕</button>
                <div class="input-actions-menu" data-attach-menu hidden role="menu" aria-hidden="true">
                  <button type="button" class="input-actions-item" id="kk-pubUpBtn" data-attach-item role="menuitem">🖼️ Ladda upp bild</button>
                  <button type="button" class="input-actions-item" id="kk-pubCamBtn" data-attach-item role="menuitem">📷 Öppna kamera</button>
                  <button type="button" class="input-actions-item" id="kk-mentionBtn" data-attach-item role="menuitem">👤 Nämn någon</button>
                </div>
              </div>

              <!-- Hidden inputs (one for upload picker, one that hints camera on mobile) -->
              <input type="file" accept="image/*" id="kk-pubImg" style="display:none">
              <input type="file" accept="image/*" capture="environment" id="kk-pubCam" style="display:none">

              <textarea name="content" placeholder="Skriv ett meddelande…" autocomplete="off"></textarea>
              <button>💬</button>
              <div id="kk-mentionBox" class="mentionbox" role="listbox" aria-label="Mention suggestions"></div>

              </form>
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
        <button id="kk-img-act-dm"     class="iconbtn" type="button" title="Skicka DM"            style="display:none">✉️ Skicka DM</button>
        <button id="kk-img-act-report" class="iconbtn" type="button" title="Rapportera"           style="display:none">⚠️ Rapportera</button>
        <button id="kk-img-act-block"  class="iconbtn" type="button" title="Blockera/Avblockera"  style="display:none">⛔ Blockera</button>

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
      <button id="kk-camFlip" type="button" title="Byt kamera">🔄</button>
      <button id="kk-camShot" type="button" title="Ta bild">📸 Ta bild</button>
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
      <button id="kk-act-dm" type="button">✉️ Skicka DM</button>
      <button id="kk-act-report" type="button">⚠️ Rapportera</button>
      <button id="kk-act-block" type="button">⛔ Blockera</button>

    </div>
            <?php if ($is_admin): ?>
          <div class="row">
            <button id="kk-act-hide" type="button">🫥 Dölj meddelande</button>
          </div>
        <?php endif; ?>
  </div>
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
  const GENDER_ICON_BASE = <?= json_encode(esc_url($plugin_root_url . 'assets/genders/')) ?>;
  const POLL_SETTINGS = Object.freeze(<?= wp_json_encode($poll_settings) ?>);

  const $ = s => document.querySelector(s);
  const pubList = $('#kk-pubList');
  const pubForm = $('#kk-pubForm');
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

  const h = {'X-WP-Nonce': REST_NONCE};

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
      const response = await originalFetch(...args);
      maybeRedirectToLogin(response).catch(()=>{});
      return response;
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
// Mark a batch of DM messages as read
async function markDMSeen(ids) {
  if (!Array.isArray(ids) || ids.length === 0) return;
  try {
    const fd = new FormData()
    fd.append('csrf_token', CSRF); 
    for (const id of ids) fd.append('dms[]', String(id));
    await fetch(`${API}/reads/mark`, {
      method: 'POST',
      credentials: 'include',
      body: fd
    });
  } catch (e) {
    console.warn('reads/mark (DM) failed', e);
  }
}

// Advance the public watermark to the server’s “now” from /sync
// (don’t use Date.now(); keep using LAST_SERVER_NOW)
async function markPublicSeen(ts) {
  if (!ts || ts <= _lastPublicSeenSent) return;
  _lastPublicSeenSent = ts;
  try {
    const fd = new FormData();
    fd.append('csrf_token', CSRF); 
    fd.append('public_since', String(ts));
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

function stopBackgroundPolling(){
  if (BACKGROUND_POLL_TIMER) {
    clearTimeout(BACKGROUND_POLL_TIMER);
    BACKGROUND_POLL_TIMER = null;
  }
}

function noteUserActivity(force = false){
  const now = Date.now();
  if (!force && now - POLL_ACTIVITY_SIGNAL_AT < 500) return;
  POLL_ACTIVITY_SIGNAL_AT = now;
  POLL_LAST_ACTIVITY_AT = now;

  if (!POLL_IS_LEADER || POLL_SUSPENDED) return;
  if (document.visibilityState === 'hidden') return;

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
  if (document.visibilityState !== 'hidden') return;
  if (MULTITAB_LOCKED) return;

  const wait = Math.max(1000, Number.isFinite(delay) ? delay : computeBackgroundPollDelay());
  stopBackgroundPolling();

  BACKGROUND_POLL_TIMER = setTimeout(async () => {
    BACKGROUND_POLL_TIMER = null;
    if (document.visibilityState !== 'hidden') return;
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
let PREFETCH_TIMER = null;
let PREFETCH_BUSY = false;

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
  if (document.visibilityState === 'hidden') {
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
  params.set('limit', isCold ? String(FIRST_LOAD_LIMIT) : '120');
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

function stopStream(){
  if (POLL_TIMER) {
    clearTimeout(POLL_TIMER);
    POLL_TIMER = null;
  }
  POLL_LAST_SCHEDULED_MS = null;
}

function scheduleStreamReconnect(delay){
  const state = desiredStreamState();
  if (!state) return;
  if (!POLL_IS_LEADER || POLL_SUSPENDED) return;

  if (POLL_TIMER) {
    clearTimeout(POLL_TIMER);
    POLL_TIMER = null;
  }

  let wait = delay;
  if (wait == null) {
    const hint = POLL_RETRY_HINT.get(pollContextKey(state));
    wait = computePollDelay(hint);
  }

  if (!Number.isFinite(wait) || wait <= 0) {
    wait = Number(POLL_SETTINGS?.hotIntervalMs) || 4000;
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
  try {
    if (next.kind === 'room') {
      await prefetchRoom(next.value);
    } else if (next.kind === 'dm') {
      await prefetchDM(next.value);
    }
  } catch (_) {}

  PREFETCH_KEYS.delete(next.key);
  PREFETCH_BUSY = false;

  if (PREFETCH_QUEUE.length) {
    schedulePrefetchTick();
  }
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
  openStream();
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
  const hiddenFor = document.visibilityState === 'hidden' && hiddenSince
    ? now - hiddenSince
    : 0;

  let base;
  if (document.visibilityState === 'hidden' && hiddenFor >= hiddenThreshold) {
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
    handleStreamSync(data, msg.state || state);
    const next = Number(data.next ?? NaN);
    if (Number.isFinite(next)) {
      pubList.dataset.last = String(Math.max(+pubList.dataset.last || -1, next));
    }
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

  POLL_BUSY = true;
  try {
    const resp = await fetch(url, { credentials: 'include', headers });
    const retryHeader = parseRetryAfter(resp.headers.get('Retry-After'));

    if (resp.status === 204 || resp.status === 304) {
      if (retryHeader != null) {
        POLL_RETRY_HINT.set(key, retryHeader * 1000);
      }
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
      return;
    }

    if (!resp.ok) {
      throw new Error(`sync ${resp.status}`);
    }

    const payload = await resp.json();
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

    const next = Number(payload?.next ?? NaN);
    if (Number.isFinite(next)) {
      pubList.dataset.last = String(Math.max(+pubList.dataset.last || -1, next));
    }

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

    broadcastSync(state, payload, { retryAfterMs: retryMs ?? undefined });
  } catch (err) {
    console.warn('sync poll failed', err);
    const fallback = Math.max(8000, (POLL_RETRY_HINT.get(key) || 8000) * 1.5);
    POLL_RETRY_HINT.set(key, fallback);
  } finally {
    POLL_BUSY = false;
    scheduleStreamReconnect();
  }
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
    let IMG_BLUR = (localStorage.getItem(BLUR_KEY) || '0') === '1';
    let SETTINGS_OPEN = false;
    let ADMIN_MENU_OPEN = false;

    function applyBlurClass(){ root?.classList.toggle('nsfw-blur', IMG_BLUR); }
    function setImageBlur(on){
      SETTINGS_OPEN = false;
      IMG_BLUR = !!on;
      localStorage.setItem(BLUR_KEY, IMG_BLUR ? '1' : '0');
      applyBlurClass();
      renderRoomTabs();
    }
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
    const MUTE_ROOMS_KEY = 'kk_muted_rooms_v1';
    const MUTE_DMS_KEY   = 'kk_muted_dms_v1';
    
    function loadSet(key){
      try{ const raw = localStorage.getItem(key)||'[]';
           const arr = JSON.parse(raw);
           return new Set(Array.isArray(arr)?arr:[]); }catch(_){ return new Set(); }
    }
    function saveSet(key, set){
      try{ localStorage.setItem(key, JSON.stringify([...set])); }catch(_){}
    }
    
    let MUTED_ROOMS = loadSet(MUTE_ROOMS_KEY);
    let MUTED_DMS   = loadSet(MUTE_DMS_KEY);
    
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
    return `<span class="tab-mute" data-mute-room="${escAttr(slug)}" title="${title}" aria-label="${title}" aria-pressed="${muted?'true':'false'}" tabindex="0" role="button">${muted?'🔕':'🔔'}</span>`;
    }
    function dmMuteHTML(id){
      const muted = isDmMuted(id);
      const title = muted ? 'Slå på ljud för DM' : 'Tysta DM';
    return `<span class="tab-mute" data-mute-dm="${id}" title="${title}" aria-label="${title}" aria-pressed="${muted?'true':'false'}" tabindex="0" role="button">${muted?'🔕':'🔔'}</span>`;
    }


  const DM_KEY = 'kk_active_dms_v1';
  function loadDMActive(){
    try{ const raw = localStorage.getItem(DM_KEY)||'[]'; const arr = JSON.parse(raw); return new Set(Array.isArray(arr)?arr:[]); }catch(_){ return new Set(); }
  }
  function saveDMActive(set){
    try{ localStorage.setItem(DM_KEY, JSON.stringify([...set])); }catch(_){}
  }
  let ACTIVE_DMS = loadDMActive();

  let BLOCKED = new Set();
  function isBlocked(id){ return BLOCKED.has(Number(id)); }
  
  function msgToHTML(m){
  const mid = Number(m.id);
  const sid = Number(m.sender_id||0);
  const who = m.sender_name || 'Okänd';
  const when = new Date((m.time||0)*1000).toLocaleTimeString('sv-SE',{hour:'2-digit',minute:'2-digit'});
  const roleClass = isAdminById?.(sid) ? ' admin' : '';
  rememberName(sid, m.sender_name);
  const gender = genderById(sid);
  const metaHTML = `<div class="bubble-meta small">${genderIconMarkup(gender)}<span class="bubble-meta-text">${who===ME_NM?'':esc(who)}<br>${esc(when)}</span></div>`;
  if ((m.kind||'chat') === 'banner'){
    return `<li class="item banner" data-id="${mid}"><div class="banner-bubble">${esc(String(m.content||''))}</div></li>`;
  }
  if ((m.kind||'chat') === 'image'){
    const u = String(m.content||'').trim();
    const alt = `Bild från ${sid === ME_ID ? 'dig' : who}`;
    return `<li class="item ${sid===ME_ID?'me':'them'}${roleClass}" data-id="${mid}" data-sid="${sid}" data-sname="${escAttr(who)}">
      <div class="bubble img"><img class="imgmsg" src="${escAttr(u)}" alt="${escAttr(alt)}" loading="lazy" decoding="async"></div>
      ${metaHTML}
    </li>`;
  }
  const txt = String(m.content||'');
  const isMention = textMentionsName?.(txt, ME_NM) && sid !== ME_ID;
  return `<li class="item ${sid===ME_ID?'me':'them'}${roleClass}" data-id="${mid}" data-sid="${sid}" data-sname="${escAttr(who)}">
    <div class="bubble${isMention?' mention':''}">${esc(txt)}</div>
    ${metaHTML}
  </li>`;
}

function msgsToHTML(items, cap=180){
  // keep only tail to avoid huge strings
  const arr = items.slice(-cap);
  return arr.map(msgToHTML).join('');
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
    ROOM_CACHE.set(key, { last: maxId, html: combined });
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
    ROOM_CACHE.set(key, { last: maxId, html: combined });
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
    pruneList(pubList, 180);

    // 🔁 re-stash the trimmed HTML so the cache doesn't bloat
    ROOM_CACHE.set(key, {
      last: Number(cached.last) || -1,
      html: pubList.innerHTML,
      bottomDist: Number.isFinite(cached.bottomDist) ? cached.bottomDist : 0
    });

    pubList.dataset.last = String(cached.last);

    const dist = Number.isFinite(cached.bottomDist) ? cached.bottomDist : 0;
    requestAnimationFrame(()=>{
      pubList.scrollTop = Math.max(0, pubList.scrollHeight - pubList.clientHeight - dist);
    });

    watchNewImages(pubList);

    if (AUTO_SCROLL && dist < 20) scrollToBottom(pubList, false);
    return true;
  }
  pubList.innerHTML = '';
  pubList.dataset.last = '-1';
  return false;
}

  function stashActive(){
  try{
    const bottomDist = Math.max(
      0,
      pubList.scrollHeight - pubList.scrollTop - pubList.clientHeight
    );
    ROOM_CACHE.set(activeCacheKey(), {
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
      alert(js.err === 'ip_banned' ? 'Regelbrott - Bannad' : 'Regelbrott - Kickad');
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
      if (!Array.isArray(items) || !items.length) return false;

      handleStreamSync({ messages: items }, target);
      return true;
    } catch (_) {
      return false;
    }
  }
  function esc(s){ const d=document.createElement('div'); d.textContent=s; return d.innerHTML; }
  function escAttr(s){ return (s==null?'':String(s)).replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
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

function renderList(el, items){
  const lastSeen = +el.dataset.last || -1;
  let maxId = lastSeen;

  const wasAtBottom = atBottom(el);

  const frag = document.createDocumentFragment();
  let mentionAdded   = false;
  let appendedCount  = 0;
  let anyFromOthers  = false;

  items.forEach(m=>{
    const mid = Number(m.id);
    if (!Number.isFinite(mid)) return;

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
            try {
              ROOM_CACHE.set(activeCacheKey(), {
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

    const fromId = Number(m.sender_id || 0);
    if (fromId && isBlocked(fromId)) return;          // hide blocked users
    if (mid <= lastSeen) return;                      // already rendered

    const li = document.createElement('li');
    li.dataset.id    = String(mid);
    li.dataset.sid   = String(m.sender_id || 0);
    li.dataset.sname = m.sender_name || '';

    if ((m.kind||'chat') === 'banner'){
      li.className = 'item banner';
      li.innerHTML = `<div class="banner-bubble">${esc(String(m.content || ''))}</div>`;
    } else {
      const roleClass = isAdminById(m.sender_id) ? ' admin' : '';
      li.className = 'item ' + (m.sender_id===ME_ID? 'me':'them') + roleClass;
      const who = m.sender_name || 'Okänd';

      rememberName(m.sender_id, m.sender_name);
      const gender = genderById(m.sender_id);

      const when = new Date((m.time||0)*1000).toLocaleTimeString('sv-SE',{hour:'2-digit',minute:'2-digit'});
      const metaHTML = `<div class="bubble-meta small">${genderIconMarkup(gender)}<span class="bubble-meta-text">${who===ME_NM?'':esc(who)}<br>${esc(when)}</span></div>`;

      let bubbleHTML = '';
      if ((m.kind||'chat') === 'image') {
        const u   = String(m.content||'').trim();
        const alt = `Bild från ${m.sender_id === ME_ID ? 'dig' : who}`;
        // inside the image branch
        bubbleHTML = `<div class="bubble img">
          <img class="imgmsg" src="${escAttr(u)}" alt="${escAttr(alt)}" loading="lazy" decoding="async">
        </div>`;
      } else {
        const txt = String(m.content||'');
        const isMentionToMe = textMentionsName(txt, ME_NM) && m.sender_id !== ME_ID;
        bubbleHTML = `<div class="bubble${isMentionToMe ? ' mention' : ''}">${esc(txt)}</div>`;
        if (isMentionToMe) {
          li.dataset.mention = '1';
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

    // update last id only when we actually appended
    el.dataset.last = String(maxId);

    watchNewImages(el);

    const shouldSnap = (AUTO_SCROLL || wasAtBottom);
    if (shouldSnap) scrollToBottom(el, false);

    // Sounds: mention has priority; otherwise generic notif if user likely didn't see it
    try {
      if (mentionAdded && !soundsMuted() && !currentChatMuted()) {
      const snd = (typeof mentionSound !== 'undefined' && mentionSound)
        ? mentionSound
        : document.getElementById('kk-mentionSound');
      if (snd) {
        snd.playbackRate = 1.35;
        snd.currentTime = 0;
        snd.play()?.catch(()=>{});
      }
    } else if (anyFromOthers && (document.hidden || !atBottom(el)) && !currentChatMuted()) {
      try { playNotifOnce(); } catch(_) {}
    }

    } catch(_) {}

    maybeToggleFab();

    // Trigger read-marking for newly visible messages
    try { markVisible(el); } catch(_){}
  } else {
    // nothing appended; keep existing dataset.last unchanged
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
  let pending = new Set();      // message IDs to mark (DM path)
  let timer   = null;
  let opts    = { ...READS_DEFAULTS };

  function shouldSendNow() {
    if (document.visibilityState !== 'visible') return false;
    return isNearBottom(pubList, opts.bottomPx);
  }

  async function flush() {
    timer = null;

    // If conditions aren’t right, try again after the debounce window.
    if (!shouldSendNow()) {
      timer = setTimeout(flush, opts.debounceMs);
      return;
    }

    // Public rooms: send watermark
    if (currentDM == null) {
      // keep your optimistic local clearing as-is — sending is gated here
      try { await markPublicSeen(LAST_SERVER_NOW); } catch(_) {}
      pending.clear();
      return;
    }

    // DMs: batch IDs
    const ids = [...pending];
    if (!ids.length) return;
    pending.clear();

    try { await markDMSeen(ids); } catch(_) {}
  }

  return function queueMark(ids, override = {}) {
    // --- keep optimistic local badge clearing exactly as-is ----------------
    if (currentDM == null) {
      // public room path
      if (currentRoom) {
        ROOM_UNREAD[currentRoom] = 0;
        renderRoomTabs();
        updateLeftCounts?.();
      }
      // enqueue a flush attempt (sending is gated by visibility + near-bottom)
    } else {
      // DM path
      UNREAD_PER[currentDM] = 0;
      renderDMSidebar();
      renderRoomTabs();
      updateLeftCounts?.();

      // collect ids (dedup)
      (ids || [])
        .map(Number)
        .filter(n => n > 0)
        .forEach(n => pending.add(n));
    }

    // Apply per-call overrides (optional)
    opts = { ...READS_DEFAULTS, ...(override || {}) };

    // Start / restart debounce window
    if (timer == null) {
      timer = setTimeout(flush, opts.debounceMs);
    }
  };
})();


function markVisible(listEl){
  if (!isVisible(listEl)) return;
  const ids = [];
  const rect = listEl.getBoundingClientRect();
  listEl.querySelectorAll('li.item').forEach(li => {
    const r = li.getBoundingClientRect();
    if (r.top >= rect.top - 20 && r.bottom <= rect.bottom + 20) {
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

  function sortUsersForList(){
    return [...USERS].sort((a,b)=>{
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
  const icon = isImage ? '📷' : '💬';

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
  const adminIcon = u.is_admin ? '🛡️ ' : '';
  const blocked = isBlocked(u.id);
  const unread = blocked ? 0 : (UNREAD_PER[u.id]||0);
  const badge  = unread>0 ? `<span class="badge-sm" data-has>${unread}</span>` : '';
  const name   = `${adminIcon}${esc(u.name)}${isMe ? ' (du)' : ''}`;

  const dmBtn  = isMe ? '' : `<button class="openbtn" data-dm="${u.id}" ${blocked?'disabled':''}
                        aria-label="${blocked?'Avblockera för att skriva':'Öppna privat med '+esc(u.name)}">✉️</button>`;

  let blockBtn = '';
  if (!isMe) {
    if (u.is_admin) {
      blockBtn = `<button class="modbtn" disabled title="Du kan inte blockera en admin">🚫</button>`;
    } else {
      blockBtn = `
        <button class="modbtn" type="button"
                data-block="${u.id}"
                aria-pressed="${blocked ? 'true' : 'false'}"
                aria-label="${blocked ? 'Avblockera' : 'Blockera'}"
                title="${blocked ? 'Avblockera' : 'Blockera'}">
          ${blocked ? '✅' : '⛔'}
        </button>`;
    }
  }

  const reportBtn = (!IS_ADMIN && !isMe)
    ? `<button class="openbtn" data-report="${u.id}" title="Rapportera användare">⚠️</button>`
    : '';

  const modBtns = (IS_ADMIN && !isMe)
    ? `<span class="modgrp">
         <button class="modbtn" data-log="${u.id}" title="Visa logg">🧾</button>
         <button class="modbtn" data-kick="${u.id}" title="Kick">🚪</button>
         <button class="modbtn" data-ban="${u.id}" title="IP Ban">📡</button>
       </span>`
    : '';

  const logoutSelfBtn = isMe
    ? `<button class="logoutbtn" data-logout="1" title="Logga ut" aria-label="Logga ut">Logga ut<b>🚪</b></button>`
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
      <div class="user-actions">${badge}${dmBtn}${blockBtn}${reportBtn}${modBtns}${logoutSelfBtn}</div>
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
    let reason = prompt(`Ange anledning till rapporten för ${whom}:`, '');
    if (reason == null) return; 
    reason = (reason || '').trim();
    if (!reason) { alert('Du måste ange en anledning.'); return; }

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
    try { USERS.forEach(u => rememberGender(u.id, u.gender)); } catch(_){}
  }

  if (js && js.unread) {
    const unread = js.unread || {};

    // keep previous counts for comparison (for sounds)
    const prevRooms = { ...(ROOM_UNREAD || {}) };
    const prevDMs   = { ...(UNREAD_PER  || {}) };

    // update to latest counts from server
    UNREAD_PER  = unread.per   || {};
    ROOM_UNREAD = unread.rooms || {};

    // zero out blocked users in DM counts
    Object.keys(UNREAD_PER).forEach(k => { if (isBlocked(+k)) UNREAD_PER[k] = 0; });

    // 🔔 Notification logic:
    try{
      const roomIncr = Object.keys(ROOM_UNREAD)
        .filter(slug => (ROOM_UNREAD[slug] || 0) > (prevRooms[slug] || 0));


      const dmIncr = Object.keys(UNREAD_PER)
       .filter(id => !isBlocked(+id) && (UNREAD_PER[id] || 0) > (prevDMs[id] || 0));

      // Prefetch newly-bumped sources so switching feels instant.
      try {
        roomIncr
          .filter(slug => slug !== currentRoom)
          .forEach(slug => queuePrefetchRoom(slug));

        dmIncr
          .map(id => Number(id))
          .filter(id => id !== Number(currentDM))
          .forEach(id => queuePrefetchDM(id));
      } catch(_) {}
      // --- ignore muted sources for sound decisions
      const roomIncrUnmuted = roomIncr.filter(slug => !isRoomMuted(slug));
      const dmIncrUnmuted   = dmIncr.filter(id => !isDmMuted(+id));

      const visible = document.visibilityState === 'visible';
      const isActiveRoom = (slug) => currentDM == null && slug === currentRoom;
      const isActiveDM   = (id)   => currentDM != null && Number(id) === Number(currentDM);

      const passiveRoomBumped = roomIncrUnmuted.some(slug => !isActiveRoom(slug));
      const passiveDmBumped   = dmIncrUnmuted.some(id   => !isActiveDM(id));

      const shouldRing =
        (!visible && (roomIncrUnmuted.length || dmIncrUnmuted.length)) ||
        (visible && (passiveRoomBumped || passiveDmBumped));

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
    if (document.visibilityState === 'visible') {
      if (currentDM != null) {
        UNREAD_PER[currentDM] = 0;
      } else if (currentRoom) {
        ROOM_UNREAD[currentRoom] = 0;
      }
    }

    // recompute total DM unread after adjustments
    DM_UNREAD_TOTAL = Object.entries(UNREAD_PER)
      .reduce((n,[k,v]) => n + (isBlocked(+k) ? 0 : (+v||0)), 0);
    

    
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
      const lock = r.member_only ? '🔒 ' : '';
      const cur  = (r.slug===currentRoom && !currentDM) ? ' (aktiv)' : '';
      const unread = ROOM_UNREAD[r.slug]||0;
      const badge  = unread>0 ? `<span class="badge-sm" data-has>${unread}</span>` : '';
      const btn = joined
        ? `<button class="openbtn" data-leave="${r.slug}" title="Ta bort #${esc(r.slug)} från flikarna">👋 Lämna</button>`
        : `<button class="openbtn" data-join="${r.slug}" title="Lägg till #${esc(r.slug)} i flikarna">➕ Gå med</button>`;
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
        const badge  = unread > 0 ? `<span class="badge-sm" data-has>${unread}</span>` : '';
        const cur    = currentDM === id ? ' aria-current="true"' : '';
        return `<div class="user" data-dm="${id}">
          <div class="user-main"><b>${esc(name)}</b></div>
          <div class="user-actions">
            ${badge}
            <button class="openbtn" data-open="${id}"${cur}>Öppna</button>
            <button class="modbtn" data-close="${id}" title="Stäng">🗑️</button>
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

        ROOM_CACHE.set(activeCacheKey(), {
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

    actDm && (actDm.textContent = '✉️ Skicka DM');
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
  let reason = prompt(`Ange anledning till rapporten för ${MSG_TARGET.name}:`, '');
  if (reason == null) return;
  reason = (reason || '').trim();
  if (!reason) { alert('Du måste ange en anledning.'); return; }
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
  let reason = prompt(`Ange anledning till rapporten för ${MSG_TARGET.name}:`, '');
  if (reason == null) return;
  reason = (reason || '').trim();
  if (!reason) { alert('Du måste ange en anledning.'); return; }

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
      imgActBlock.textContent = blocked ? '✅ Avblockera' : '⛔ Blockera';
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
      const lock  = r.member_only ? ' 🔒' : '';
      const count = ROOM_UNREAD[r.slug] || 0;
      const badge = count > 0 ? `<span class="badge" data-has>${count}</span>` : '';
      const selected = (r.slug===currentRoom && !currentDM) ? 'true' : 'false';
      return `<button class="tab" data-room="${r.slug}" aria-selected="${selected}">${roomMuteHTML(r.slug)}${esc(r.title)}${lock}${badge}</button>`;
    }).join('');

  const dmBtns = [...ACTIVE_DMS]
    .filter(id => !isBlocked(id))
    .map(id => {
      const name  = nameById(id) || ('#'+id);
      const count = UNREAD_PER[id] || 0;
      const badge = count > 0 ? `<span class="badge" data-has>${count}</span>` : '';
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
    ? `🔔 Slå på ljud för ${activeName}`
    : `🔕 Tysta ${activeName}`;

  const autoLabel = AUTO_SCROLL
    ? '🔓 Autoscroll på (klicka för att låsa)'
    : '🔒 Autoscroll av (klicka för att låsa upp)';

  const blurLabel = IMG_BLUR
    ? '👁️ Visa bilder'
    : '🙈 Censurera bilder';

  const allMuted = allChatsMuted();
  const allMuteLabel = allMuted
    ? '🔔 Slå på ljud för alla'
    : '🔕 Tysta alla';

  const adminMenu = !HAS_ADMIN_TOOLS ? '' : `
    <div class="tab-settings tab-settings-admin${ADMIN_MENU_OPEN ? ' is-open' : ''}" data-admin-root>
      <button class="tabicons" data-admin-toggle="1"
              title="Adminverktyg"
              aria-label="Adminverktyg"
              aria-haspopup="menu"
              aria-expanded="${ADMIN_MENU_OPEN ? 'true' : 'false'}">🛠️</button>
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
    <span class="spacer"></span>
    <div class="tab-settings${SETTINGS_OPEN ? ' is-open' : ''}" data-settings-root>
      <button class="tabicons" data-settings-toggle="1"
              title="Fler inställningar"
              aria-label="Fler inställningar"
              aria-haspopup="menu"
              aria-expanded="${SETTINGS_OPEN ? 'true' : 'false'}">⚙️</button>
      <div class="tab-settings-menu${SETTINGS_OPEN ? ' is-open' : ''}"
           role="menu"
           aria-hidden="${SETTINGS_OPEN ? 'false' : 'true'}">
        <button type="button" class="tab-settings-item" data-settings-blur="1" role="menuitem">${blurLabel}</button>
        <button type="button" class="tab-settings-item" data-settings-autoscroll="1" role="menuitem">${autoLabel}</button>
        <button type="button" class="tab-settings-item" data-settings-mute-active="1" role="menuitem">${activeMuteLabel}</button>
        <button type="button" class="tab-settings-item" data-settings-mute-all="1" role="menuitem">${allMuteLabel}</button>
        <button type="button" class="tab-settings-item" data-settings-logout="1" role="menuitem">🚪 Logga ut</button>
      </div>
    </div>
    ${adminMenu}`;

  roomTabs.innerHTML = roomBtns + dmBtns + controls;
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

  muteFor(1200);
  stopStream();
  stashActive();
  currentDM = null;
  currentRoom = slug;
  ROOM_UNREAD[slug] = 0;
  renderRoomTabs();

  const cacheHit = applyCache(cacheKeyForRoom(slug));
  setComposerAccess();
  showView('vPublic');

  let snapshotPromise = null;
  if (cacheHit) {
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
            muteFor(1200);
        stopStream();
        currentRoom = next;
        const cacheHit = applyCache(cacheKeyForRoom(currentRoom));
        setComposerAccess();
        showView('vPublic');

        let snapshotPromise = null;
        if (cacheHit) {
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
      for (let i = 0; i < removeCount; i++) items[i].remove();
    }
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

  const prevLast    = +pubList.dataset.last || -1;
  const isCold      = prevLast < 0;
  const wasAtBottom = atBottom(pubList);

  if (context.kind === 'room') {
    const payload = Array.isArray(js?.messages) ? js.messages : [];
    const items   = isCold ? payload.slice(-FIRST_LOAD_LIMIT) : payload;

    renderList(pubList, items);
    markVisible(pubList);
    watchNewImages(pubList);
    updateReceipts(pubList, items, /*isDM=*/false);

    if (items.length && (AUTO_SCROLL || wasAtBottom)) {
      scrollToBottom(pubList, false);
    }

    const currentLast = +pubList.dataset.last || -1;
    const allMax = items.reduce((mx, m) => Math.max(mx, Number(m.id) || -1), currentLast);
    pubList.dataset.last = String(allMax);

    ROOM_CACHE.set(cacheKeyForRoom(context.room), {
      last: +pubList.dataset.last || -1,
      html: pubList.innerHTML
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

    renderList(pubList, items);
    markVisible(pubList);
    watchNewImages(pubList);
    updateReceipts(pubList, items, /*isDM=*/true);

    if (items.length && (AUTO_SCROLL || wasAtBottom)) {
      scrollToBottom(pubList, false);
    }

    const currentLast = +pubList.dataset.last || -1;
    const allMax = items.reduce((mx, m) => Math.max(mx, Number(m.id) || -1), currentLast);
    pubList.dataset.last = String(allMax);

    ROOM_CACHE.set(cacheKeyForDM(context.to), {
      last: +pubList.dataset.last || -1,
      html: pubList.innerHTML
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

if (document.visibilityState === 'hidden') {
  POLL_HIDDEN_SINCE = Date.now();
  scheduleBackgroundPoll();
}

document.addEventListener('visibilitychange', () => {
  if (document.visibilityState === 'hidden') {
    POLL_HIDDEN_SINCE = Date.now();
    suspendStream();
    scheduleBackgroundPoll();
  } else {
    POLL_HIDDEN_SINCE = 0;
    stopBackgroundPolling();
    resumeStream();
    noteUserActivity(true);
    pollActive().catch(()=>{});
  }
});

window.addEventListener('focus',  () => { noteUserActivity(true); pollActive().catch(()=>{}); restartStream(); });
window.addEventListener('online', () => { pollActive().catch(()=>{}); restartStream(); });


  function showView(id){ document.querySelectorAll('.view').forEach(v=>v.removeAttribute('active')); document.getElementById(id).setAttribute('active',''); }

async function openDM(id) {
  if (isBlocked(id)) { showToast('Du har blockerat denna användare'); return; }
  muteFor(1200);

  stopStream();

  // Remember current scroll/content before switching
  stashActive();

  // Ensure the DM appears in the sidebar/tabs (don't render yet)
  ACTIVE_DMS.add(Number(id));
  saveDMActive(ACTIVE_DMS);

  // 👉 Set state FIRST so renderers know which tab is active
  currentDM = Number(id);
  LAST_OPEN_DM = currentDM;
  UNREAD_PER[currentDM] = 0;

  // Recompute total DM unread so the left badge updates immediately
  try {
    DM_UNREAD_TOTAL = Object.entries(UNREAD_PER)
      .reduce((sum, [k, v]) => sum + (isBlocked(+k) ? 0 : (+v || 0)), 0);
  } catch (_) {}

  // Now render UI that depends on currentDM
  renderDMSidebar();
  renderRoomTabs();
  updateLeftCounts?.();

  // Highlight the opened DM in the People list (if visible)
  userListEl.querySelectorAll('.openbtn[aria-current]').forEach(x => x.removeAttribute('aria-current'));
  userListEl.querySelector(`.openbtn[data-dm="${currentDM}"]`)?.setAttribute('aria-current', 'true');

  // 2) render from cache immediately
  const cacheHit = applyCache(cacheKeyForDM(currentDM));

  setComposerAccess();
  showView('vPublic');

  let snapshotPromise = null;
  if (cacheHit) {
    markVisible(pubList);
  } else {
    snapshotPromise = loadHistorySnapshot({ kind: 'dm', to: currentDM });
  }

  const syncPromise = pollActive(true).catch(()=>{});
  openStream();
  if (snapshotPromise) {
    await snapshotPromise.catch(()=>{});
  }
  await syncPromise;



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

    let file = new File([blob], 'camera.jpg', { type: 'image/jpeg' });

    showToast('Komprimerar…');
    file = await compressImageIfNeeded(file);

    showToast('Laddar bild…');
    const url = await uploadImage(file);

    const ok = await sendImageMessage(url);
    if (ok) { await pollActive(); showToast('Bild skickad'); }
  } catch(e){
    showToast(e?.message || 'Uppladdning misslyckades');
  } finally {
    if (camShot) camShot.disabled = false;
  }
}

// Controls
camShot?.addEventListener('click', async ()=>{
  await takeWebcamPhoto();
  await closeWebcamModal();
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


function escapeHtml(s){
  return s.replace(/[&<>\"']/g, ch =>
    ch === '&' ? '&amp;' :
    ch === '<' ? '&lt;'  :
    ch === '>' ? '&gt;'  :
    ch === '\"' ? '&quot;': '&#39;'
  );
}

function appendPendingMessage(text){
  const li = document.createElement('li');
  li.className = 'item me pending';
  li.dataset.temp = String(Date.now());
  const now = new Date();
  const hh = String(now.getHours()).padStart(2,'0');
  const mm = String(now.getMinutes()).padStart(2,'0');
  li.innerHTML = `
    <div class="msg"><div class="bubble chat">${escapeHtml(text)}</div></div>
    <div class="small"><span class="time">${hh}:${mm}</span> <span class="receipt">✓</span></div>
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

pubForm.addEventListener('submit', async (e)=>{
  e.preventDefault();

  const fd = new FormData(pubForm);
  fd.append('csrf_token', CSRF);

  const txt = (fd.get('content')||'').toString().trim();
  if (!txt) return;

  if (currentDM) fd.append('recipient_id', String(currentDM));
  else fd.append('room', currentRoom);

  const pending = appendPendingMessage(txt);

  try{
    const r  = await fetch(API + '/message', { method:'POST', body: fd, credentials:'include', headers:h });
    const js = await r.json().catch(()=>({}));

    if (!r.ok || !js.ok) {
      pending?.classList.remove('pending'); pending?.classList.add('error');
      showToast(js.err==='no_room_access' ? 'Endast för medlemmar' : (js.cause || 'Kunde inte skicka'));
      return;
    }

    if (js.deduped) {
      pending?.remove();
      showToast('Spam - Ditt meddelande avvisades.');
      return;
    }

    pubForm.reset();
    pending?.remove();

    await pollActive();
  }catch(_){
    pending?.classList.remove('pending'); pending?.classList.add('error');
    showToast('Tekniskt fel');
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
  async function sendImageMessage(url){
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('kind', 'image');
    fd.append('image_url', url);
    if (currentDM) fd.append('recipient_id', String(currentDM));
    else fd.append('room', currentRoom);
    const r  = await fetch(API + '/message', { method:'POST', body: fd, credentials:'include', headers:h });
    const js = await r.json().catch(()=>({}));
    if (!r.ok || !js.ok) { showToast(js.cause || js.err || 'Kunde inte skicka bild'); return false; }
    if (js.deduped) { showToast('Spam - Duplicerad bild avvisades.'); return false; }
    return true;
  }

// Upload picker
pubUpBtn?.addEventListener('click', () => {
  closeAttachmentMenu();
  pubImgInp?.click();
});

pubImgInp?.addEventListener('change', async ()=>{
  const file = pubImgInp.files?.[0]; pubImgInp.value='';
  if (!file) return;
  try{
    showToast('Komprimerar…');
    const small = await compressImageIfNeeded(file);
    showToast('Laddar bild…');
    const url = await uploadImage(small);
    const ok  = await sendImageMessage(url);
    if (ok) { await pollActive(); showToast('Bild skickad'); }
  }catch(e){
    showToast(e?.message || 'Uppladdning misslyckades');
  }
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
  try{
    showToast('Komprimerar…');
    const small = await compressImageIfNeeded(file);
    showToast('Laddar bild…');
    const url = await uploadImage(small);
    const ok  = await sendImageMessage(url);
    if (ok) { await pollActive(); showToast('Bild skickad'); }
  }catch(e){
    showToast(e?.message || 'Uppladdning misslyckades');
  }
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
      imgActBlock.textContent = blocked ? '✅ Avblockera' : '⛔ Blockera';

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
    try { await fetch(API + '/ping', { credentials:'include', headers:h }); } catch(_){}
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
    if (document.visibilityState === 'hidden') return;

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
      if (document.visibilityState === 'hidden') return;
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
            <button class="modbtn" data-resolve="${id}" title="Resolve">✅</button>
            <button class="modbtn" data-delete="${id}"  title="Delete">🗑️</button>
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
  keepAliveTimer = setTimeout(pingOnce, delay);
}

async function pingOnce() {
  try {
    const r  = await fetch(`${PING_URL}?ts=${Date.now()}`, {
      method: 'GET',
      credentials: 'include',
      cache: 'no-store',
      keepalive: true,
    });
    const js = await r.json().catch(()=>null);

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
  const root    = document.getElementById('kkchat-root');
  const list    = root?.querySelector('.list');
  const inputBar= root?.querySelector('.inputbar');
  const vv      = window.visualViewport;

  function applyKbOffset(){
    if (!vv || !root || !inputBar) return;
    const kbOffset = Math.max(0, (window.innerHeight - vv.height - vv.offsetTop));
    document.documentElement.style.setProperty('--kb-offset', kbOffset + 'px');
    if (kbOffset > 0) {
      document.documentElement.classList.add('kb-open');
    } else {
      document.documentElement.classList.remove('kb-open');
    }
    if (list) {

      list.style.paddingBottom = `calc(80px + env(safe-area-inset-bottom) + ${kbOffset}px)`;
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

  <?php endif; ?>
  </div>
  <?php
  return ob_get_clean();
});