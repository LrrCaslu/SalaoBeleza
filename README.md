# Essenza Beauty Studio — ServBay Windows, instalação direta

Aplicação de salão de beleza com interface em tons nude e rosé, agendamento integrado ao MySQL 8, conta do cliente e painel administrativo PHP. **Esta distribuição inicia o processo de instalação ao abrir o próprio site.**

## Colocar para funcionar

1. No **ServBay**, inicie **PHP 8.4** (extensões `pdo_mysql`, `mbstring`, `fileinfo`, `gd`) e **MySQL 8.4** (porta 3306).
2. Extraia a pasta `essenza-beauty-studio` em `C:\ServBay\www\` (ou no local de sua preferência).
3. Em **Sites**, edite o site já cadastrado e selecione **Tipo PHP**, **PHP 8.4**, **Regra de rewrite Default (PHP)** e **Diretório raiz do site:** `C:\ServBay\www\essenza-beauty-studio\public`. Se o projeto estiver em outro lugar, use **a pasta `public` que contém `index.php`**.
4. Salve as alterações e abra seu domínio local cadastrado (por exemplo, `https://essenza.com` se ele já estiver configurado para resolver no seu computador).
5. O primeiro acesso tenta criar o banco `essenza_beauty`, as tabelas e os dados iniciais **automaticamente**. Se a senha do MySQL tiver mudado, surge uma tela segura para informá-la.
6. Crie seu **nome, e-mail e senha administrativa** na página exibida; depois disso o sistema abre o painel.

**Não execute BAT, não importe SQL e não crie `.env` manualmente.** Não apague o `.env` de uma instalação antiga: ele é preservado e a aplicação tenta reparar a conexão, se necessário.

O site público usa `/`, agendamentos usam `/agendar`, a área do cliente usa `/minha-conta` e o painel usa `/admin`.

## Como funciona a instalação automática

- Só é permitida uma configuração inicial a partir do próprio computador (`127.0.0.1` ou `::1`).
- Os dados locais de MySQL informados para esta instalação ficam em um arquivo de configuração **fora de `public/`**. Eles são utilizados na configuração inicial e o arquivo é excluído após o provisionamento.
- O instalador cria um **usuário MySQL exclusivo**, com senha aleatória e permissões limitadas ao banco da aplicação; guarda essa conta no `.env` privado e não continua usando root para operar o site.
- O instalador executa `database/schema.sql` e carrega `database/seed.sql` somente se os dados iniciais do salão ainda não existirem. Ele não descarta bancos, tabelas nem registros existentes.
- A conta administrativa não tem senha predefinida: você a cria no primeiro acesso. Depois de criar a conta, a página de configuração fica desabilitada.
- Se o MySQL estiver parado ou usar credenciais diferentes, a tela de configuração apresentará campos para host local, porta, banco, usuário e senha.

> **Segurança:** este pacote foi preparado exclusivamente para **instalação local no ServBay**. Não o publique como está em servidores acessíveis pela internet. Antes da produção, remova qualquer arquivo de credencial de bootstrap, configure domínio e HTTPS públicos, adicione monitoramento, revisão de permissões, backup, política LGPD e integrações de notificações.

## Site sem CSS ou imagens

Verifique o documento root: precisa ser **`...\essenza-beauty-studio\public`**. Teste `https://SEU-DOMINIO/assets/css/style.css` e `https://SEU-DOMINIO/assets/img/hero.webp`.

Se os arquivos retornarem `404`, o ServBay está apontado para a pasta errada. Se aparecerem normalmente, atualize o navegador com `Ctrl + F5`.

## Site com “Banco de dados indisponível”

Se o sistema já tiver sido configurado, confira que o MySQL está ativo na porta 3306. Em novas instalações, o próprio sistema mostra a configuração inicial quando não consegue usar o banco.

Se o processo automático falhar, execute a instalação manual com `scripts/instalar.php` no PHP CLI do ServBay ou configure a conexão pelo formulário que aparece ao visitar o site. Não envie senhas nem o `.env` em capturas de tela.

## Recursos incluídos

- Site público, páginas de serviços, profissionais, galeria, avaliações e contato.
- Agendamento em etapas, calendário com horários de profissionais, feriados/folgas, pausas e bloqueios.
- Transações e locks por dia/profissional para evitar sobreposição em requisições simultâneas.
- Área do cliente (entrar, consultar, cancelar, solicitar reagendamento, atualizar perfil e favoritos).
- Dashboard administrativo: agendamentos, serviços, profissionais, jornadas, clientes, permissões e configurações.
- API PHP, sessões, validação de dados, tokens CSRF, proteção XSS, PDO com prepared statements e senhas com hash.
- Compartilhamento manual no WhatsApp (sem envio automático e sem alegar que envia notificações sozinho).

Arquivos importantes: `public/index.php`, `public/assets`, `src`, `database/schema.sql`, `database/seed.sql`, `src/setup.php`, `docs/ROTAS_API.md`.

## Testes e limites

Os testes de sintaxe PHP e JavaScript, a análise do SQL e testes unitários básicos de horários podem ser executados localmente. **Não foi possível executar testes de ponta a ponta com o seu MySQL 8.4 do ServBay a partir deste ambiente.** A verificação final de conexão, criação de usuário e persistência ocorre quando você abre o domínio localmente. O projeto não inclui envio automático por WhatsApp; isso requer integração oficial adicional.
