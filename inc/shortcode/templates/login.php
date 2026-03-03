<?php if (!defined('ABSPATH')) exit; ?>
    <div class="login">
  <div class="kk-login-overlay" aria-hidden="true" style="display:none">
    <div class="kk-login-spinner" role="status" aria-label="Laddar"></div>
  </div>
      <?php if ($wp_logged): ?>
        <h1>Välkommen till KKchatten</h1>
        <p>Du är inloggad som <b><?= kkchat_html_esc($wp_username) ?></b>. Kön hämtas inte automatiskt. Vänligen fyll i ditt kön.</p>
        <form id="kk-loginForm" method="post">
          <input type="hidden" name="csrf_token" value="<?= kkchat_html_esc($session_csrf) ?>">
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
            Jag är 18+ och godkänner användarvillkoren
          </label>

          <button>Logga in i chatten</button>
          <div id="kk-loginErr" class="err" style="display:none"></div>
        </form>
      <?php else: ?>
        <h1>Välj ett smeknamn</h1>
        <p>Du kommer att visas som <b>namn-guest</b>. Namnet blir ledigt igen när du loggar ut eller blir inaktiv.</p>
        <form id="kk-loginForm" method="post">
          <input type="hidden" name="csrf_token" value="<?= kkchat_html_esc($session_csrf) ?>">
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
            Jag är 18+ och godkänner användarvillkoren
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
    let msg =
      js.err === 'ip_banned' ? 'Regelbrott - Bannad' :
      js.err === 'kicked'    ? 'Regelbrott - Kickad' :
      (js.err || 'Ogiltigt val');
    if (js.err === 'ip_banned' || js.err === 'kicked') {
      const code = Number(js.block_id);
      if (Number.isFinite(code) && code > 0) {
        msg += ` (Felkod: ${code})`;
      }
    }
    e.textContent = msg;
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
  
