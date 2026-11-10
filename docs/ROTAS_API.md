# API REST — Essenza Beauty Studio

Todas as rotas estão sob `/api`. Os parâmetros de consulta são URL-encoded. Envie `Content-Type: application/json` e `X-CSRF-Token: <meta name="csrf-token">` em **POST/PUT/PATCH/DELETE**, inclusive no login. Os cookies HttpOnly mantêm a sessão. Respostas usam JSON, inclusive em erros (`{"error":"..."}`).

## Público

| Método | Rota | Função |
|---|---|---|
| GET | `/api/catalog` | Categorias, serviços ativos, equipe, galeria, depoimentos e informações públicas |
| GET | `/api/available-days?service_id=4&staff_id=0&month=2026-11` | Dias com pelo menos uma vaga no mês |
| GET | `/api/availability?service_id=4&staff_id=0&date=2026-11-12` | Horários vagos e IDs dos profissionais elegíveis |
| POST | `/api/bookings` | Reserva com conta autenticada ou cadastro atômico em `customer` |
| POST | `/api/client/login` | Login por telefone/senha |
| POST | `/api/client/register` | Cadastro (nome, telefone, e-mail opcional, senha, aceite) |
| POST | `/api/client/logout` | Encerra sessão |
| GET/PUT | `/api/client/profile` | Perfil próprio |
| GET | `/api/client/bookings` | Histórico e futuros agendamentos próprios |
| POST | `/api/client/bookings/{id}/cancel` | Cancelamento com janela mínima configurável |
| POST | `/api/client/bookings/{id}/reschedule` | Reagendamento com validação da agenda |
| GET/POST | `/api/client/favorites` | Favoritos do cliente |

**Exemplo de reserva para cliente já conectado:**

```json
{"service_id":4,"staff_id":0,"date":"2026-11-12","time":"14:00","notes":""}
```

**Exemplo de primeira reserva (conta é criada dentro da transação):**

```json
{
  "service_id":4,
  "staff_id":4,
  "date":"2026-11-12",
  "time":"14:00",
  "customer":{
    "name":"Cliente de Teste",
    "phone":"48999990000",
    "email":null,
    "password":"SenhaDeTeste123",
    "consent":true
  }
}
```

## Administração

Login: `POST /api/admin/login` (e-mail e senha); saída: `POST /api/admin/logout`.

| Método | Rota | Função |
|---|---|---|
| GET | `/api/admin/dashboard` | KPIs reais |
| GET/POST | `/api/admin/appointments` | Consultar por período, status e profissional / criar agendamento manual |
| PATCH | `/api/admin/appointments/{id}` | Alterar status |
| POST | `/api/admin/appointments/{id}/reschedule` | Reagendar |
| GET/POST | `/api/admin/services` | Listar e cadastrar serviços |
| PUT | `/api/admin/services/{id}` | Atualizar/desativar serviço |
| GET/POST | `/api/admin/staff` | Listar e cadastrar equipe |
| PUT | `/api/admin/staff/{id}` | Atualizar equipe, associações e jornada |
| GET/POST | `/api/admin/clients` | Pesquisar e cadastrar clientes |
| GET | `/api/admin/clients/{id}` | Detalhe, histórico e observações internas |
| POST | `/api/admin/clients/{id}/notes` | Registra observação pertinente com autorização confirmada |
| DELETE | `/api/admin/clients/{id}/notes/{note_id}` | Exclui observação interna (gestão) |
| GET/PUT | `/api/admin/settings` | Conteúdo, contatos, regras e horários de funcionamento |
| GET/POST | `/api/admin/blocks` | Consultar/inserir bloqueios por profissional |
| DELETE | `/api/admin/blocks/{id}` | Remover bloqueio |
| POST | `/api/admin/closures` | Registrar feriado ou fechamento |
| DELETE | `/api/admin/closures/{yyyy-mm-dd}` | Remover fechamento |
| POST | `/api/admin/staff-days-off` | Folga de profissional |
| DELETE | `/api/admin/staff-days-off/{staff_id}/{yyyy-mm-dd}` | Remover folga |
| POST | `/api/admin/gallery` | Adicionar fotografia/legenda |
| DELETE | `/api/admin/gallery/{id}` | Remover da galeria |
| POST | `/api/admin/upload-image` | Upload multipart (`image`) reprocessado em WebP, até 4 MB |

Papéis: `owner` e `manager` podem editar configurações, equipe, serviços, galeria e feriados. `reception` pode usar agenda, status, clientes e bloqueios. Todos exigem sessão administrativa ativa.

## Conflitos e disponibilidade

Cada criação/reagendamento **não confia** na disponibilidade previamente consultada pelo navegador. Dentro de uma transação InnoDB, obtém-se mutex por profissional e data (`booking_day_locks` com `SELECT ... FOR UPDATE`), recalculam-se horários com leituras de bloqueio, e somente então grava-se a reserva. O cliente recebe `409 Conflict` se a vaga não estiver livre.

Os horários são armazenados no fuso configurado no salão; `TIME` e `DATE` são horários de parede locais (não UTC). Os registros de auditoria em `TIMESTAMP` seguem a configuração de conexão do MySQL.
