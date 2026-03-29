<?php if (!defined('ABSPATH')) exit; ?>
<?php
$custom_logo_url = '';
if (function_exists('has_custom_logo') && has_custom_logo()) {
  $logo_id = (int) get_theme_mod('custom_logo');
  if ($logo_id > 0) {
    $custom_logo_url = wp_get_attachment_image_url($logo_id, 'full') ?: '';
  }
}
?>
<div class="login">
  <div class="kk-login-overlay" aria-hidden="true" style="display:none">
    <div class="kk-login-spinner" role="status" aria-label="Laddar"></div>
  </div>

  <div class="kk-login-shell">
    <aside class="kk-login-branding" aria-label="Varumärke">
      <div class="kk-login-logo-slot">
        <?php if (!empty($custom_logo_url)): ?>
          <img src="<?= esc_url($custom_logo_url) ?>" alt="KKompis logotyp" class="kk-login-logo-img">
        <?php else: ?>
          <span class="kk-login-logo-fallback" aria-hidden="true">KK</span>
        <?php endif; ?>
      </div>
      <h1>Välkommen till vår sexchatt!</h1>
      <p>En gratis sexchatt och anonym sexchatt för dig som vill chatta utan registrering. Här kan du snabbt komma i kontakt med nya personer, skriva i öppna rum, ta samtalet vidare i privata DM och njuta av en sexchatt som är enkel, direkt och full av möjligheter. Oavsett om du vill flirta, skriva snuskigt eller hitta någon för mer privat kontakt är vår sexchatt byggd för att göra det lätt att komma igång direkt</p>
    </aside>

    <section class="kk-login-form-card" aria-label="Inloggning">
      <?php if ($wp_logged): ?>
        <h2>Fortsätt som <?= kkchat_html_esc($wp_username) ?></h2>
        <p class="kk-login-lead">Du är inloggad i WordPress. Välj kön för att slutföra inloggningen till chatten.</p>
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
            <a href="<?= esc_url(home_url('/anvandarvillkor-kkchatt/')) ?>" target="_blank" rel="noopener">Klicka här för att läsa våra användarvillkor</a>.
          </p>
          <label class="kk-agree">
            <input type="checkbox" id="login_agree" name="login_agree" required>
            Jag är 18+ och godkänner användarvillkoren
          </label>

          <button>Logga in i chatten</button>
          <div id="kk-loginErr" class="err" style="display:none"></div>
        </form>
      <?php else: ?>
        <h2>Välj ditt användarnamn</h2>
        <p class="kk-login-lead">Du visas som <b>namn-guest</b>. Namnet blir ledigt när du loggar ut eller blir inaktiv.</p>
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
            <a href="<?= esc_url(home_url('/anvandarvillkor-kkchatt/')) ?>" target="_blank" rel="noopener">Klicka här för att läsa våra användarvillkor</a>.
          </p>
          <label class="kk-agree">
            <input type="checkbox" id="login_agree" name="login_agree" required>
            Jag är 18+ och godkänner användarvillkoren
          </label>

          <button>Logga in i chatten</button>
          <div id="kk-loginErr" class="err" style="display:none"></div>
        </form>
      <?php endif; ?>
    </section>
  </div>
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
  
