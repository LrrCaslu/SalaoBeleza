<?php
declare(strict_types=1);
function pageStart(string $title, string $description = ''): void {
    $s=salon();$name=$s['salon_name']??'Essenza Beauty Studio';
    $parts=explode(' ',trim($name));$brandTitle=array_shift($parts);$brandSub=implode(' ',$parts)?:'Beauty Studio';
    $description=$description?:($s['hero_description']??'Salão de beleza e agendamento online.');
    $current=routePath();
    $color=preg_match('/^#[a-fA-F0-9]{6}$/',(string)($s['brand_color']??''))?$s['brand_color']:'#b9897d';
    ?>
<!doctype html><html lang="pt-BR"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#f7f1ec"><meta name="description" content="<?=e($description)?>">
<meta property="og:type" content="website"><meta property="og:title" content="<?=e($title.' • '.$name)?>">
<meta property="og:description" content="<?=e($description)?>"><meta property="og:image" content="<?=e(asset('img/hero.webp'))?>">
<link rel="canonical" href="<?=e(url(ltrim($current,'/')))?>"><title><?=e($title.' • '.$name)?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?=e(asset('css/style.css'))?>"><style>:root{--rose:<?=e($color)?>}</style>
<meta name="csrf-token" content="<?=e(csrfToken())?>"><script>window.ESSENZA={base:<?=json_encode(appBase(),JSON_UNESCAPED_SLASHES)?>,salon:<?=json_encode($name,JSON_UNESCAPED_UNICODE)?>};</script>
</head><body>
<div class="announcement">Cuidado com carinho, beleza com essência <span aria-hidden="true">✦</span> <?=e($s['subtitle']??'')?></div>
<header class="site-header" id="top"><div class="container nav-wrap">
<a class="brand" href="<?=e(url())?>" aria-label="<?=e($name)?> — Início"><?php if(!empty($s['logo'])):?><img class="brand-logo" alt="" src="<?=e(img($s['logo']))?>"><?php else:?><svg viewBox="0 0 64 54" aria-hidden="true" class="brand-flower"><path d="M32 45C16 33 19 17 32 4c13 13 16 29 0 41Zm0 0C17 47 6 35 4 18c15 0 27 7 28 27Zm0 0c15 2 26-10 28-27C45 18 33 25 32 45Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg><?php endif;?><span class="brand-lines"><strong><?=e(mb_strtoupper($brandTitle))?></strong><small><?=e(mb_strtoupper($brandSub))?></small></span></a>
<button class="mobile-toggle icon-btn" type="button" aria-label="Abrir menu" aria-expanded="false" aria-controls="nav-menu" id="mobileToggle"><i data-lucide="menu"></i></button>
<nav class="nav-links" id="nav-menu" aria-label="Navegação principal"><a href="<?=e(url())?>">Início</a><a href="<?=e(url('#sobre'))?>">Sobre</a><a href="<?=e(url('#servicos'))?>">Serviços</a><a href="<?=e(url('#profissionais'))?>">Profissionais</a><a href="<?=e(url('#galeria'))?>">Galeria</a><a href="<?=e(url('#contato'))?>">Contato</a><a class="account-link" href="<?=e(url('minha-conta'))?>"><i data-lucide="user-round"></i> Minha conta</a><a class="btn btn-primary nav-book" href="<?=e(url('agendar'))?>"><i data-lucide="calendar-days"></i> Agendar horário</a></nav></div></header><main>
<?php }
function pageEnd(): void {
    $s=salon();$wa=preg_replace('/\D/','',(string)($s['whatsapp']??'')); ?>
</main><footer class="footer"><div class="container footer-grid"><div><a class="footer-name" href="<?=e(url())?>"><?=e($s['salon_name']??'Essenza Beauty Studio')?></a><p>Um encontro entre beleza, bem-estar e cuidado.</p></div><div><h4>Explore</h4><a href="<?=e(url('#servicos'))?>">Serviços</a><a href="<?=e(url('#profissionais'))?>">Nossa equipe</a><a href="<?=e(url('agendar'))?>">Agendar horário</a></div><div><h4>Informações</h4><a href="<?=e(url('#contato'))?>">Contato</a><a href="<?=e(url('privacidade'))?>">Privacidade e LGPD</a><a href="<?=e(url('minha-conta'))?>">Meus agendamentos</a></div><div><h4>Fale com a gente</h4><p><?=e($s['phone']??'')?></p><p><?=e($s['email']??'')?></p><div class="social-links"><?php if($wa):?><a target="_blank" rel="noopener noreferrer" href="<?=e('https://wa.me/'.$wa)?>" aria-label="WhatsApp"><i data-lucide="message-circle"></i></a><?php endif; ?><?php if(!empty($s['instagram'])):?><a target="_blank" rel="noopener noreferrer" href="<?=e($s['instagram'])?>" aria-label="Instagram"><i data-lucide="instagram"></i></a><?php endif;?></div></div></div><div class="container footer-bottom">© <?=date('Y')?> <?=e($s['salon_name']??'Essenza')?>. Todos os direitos reservados. <span>Feito para momentos especiais.</span></div></footer>
<?php if($wa):?><a class="whatsapp-float" href="<?=e('https://wa.me/'.$wa.'?text='.rawurlencode('Olá! Gostaria de saber mais sobre os serviços e horários.'))?>" target="_blank" rel="noopener noreferrer" aria-label="Fale com o salão pelo WhatsApp"><i data-lucide="message-circle"></i></a><?php endif; ?>
<div class="toast" id="toast" role="status" aria-live="polite"></div>
<script src="https://unpkg.com/lucide@0.468.0/dist/umd/lucide.min.js" defer></script><script src="<?=e(asset('js/app.js'))?>" defer></script>
</body></html><?php }
