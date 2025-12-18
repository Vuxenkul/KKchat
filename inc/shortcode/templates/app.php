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
    <strong id="kk-explicitTitle">Godkänn bilduppladning</strong>
    <p class="kk-explicit-text">Ladda bara upp lagliga 18+ bilder med samtycke; XXX är förvalt – avmarkera bara om bilden är SFW/inte av sexuell natur - Felaktig märkning kan leda till ban.</p>
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






