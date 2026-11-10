<?php
declare(strict_types=1);

function apiHandle(string $path,string $method): never {
    if (!in_array($method,['GET','POST','PUT','PATCH','DELETE'],true)) fail('Método não permitido.',405);
    if (!in_array($method,['GET','HEAD'],true)) csrfCheck();

    if ($path==='/api/admin/upload-image' && $method==='POST') {
        requireAdmin(['owner','manager']);
        $file=$_FILES['image'] ?? null;
        if (!$file || is_array($file['name']) || $file['error'] !== UPLOAD_ERR_OK) fail('Selecione uma imagem válida.');
        if ($file['size'] > 4*1024*1024 || $file['size'] < 100) fail('A imagem deve ter até 4 MB.');
        if (!function_exists('imagecreatefromstring') || !function_exists('imagewebp')) fail('Ative a extensão PHP GD com suporte a WebP.');
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!in_array($mime,['image/jpeg','image/png','image/webp'],true)) fail('Envie JPG, PNG ou WebP.');
        $details=@getimagesize($file['tmp_name']);
        if (!$details || $details[0]<100 || $details[1]<100 || $details[0]*$details[1]>20000000) fail('Dimensões de imagem inválidas.');
        $image=@imagecreatefromstring(file_get_contents($file['tmp_name']));
        if (!$image) fail('Não foi possível abrir a imagem.');
        $width=imagesx($image);$height=imagesy($image);
        if (max($width,$height)>1600) {
            $ratio=1600/max($width,$height);
            $scaled=imagescale($image,max(1,(int)round($width*$ratio)),max(1,(int)round($height*$ratio)));
            imagedestroy($image);
            if (!$scaled) fail('Não foi possível redimensionar a imagem.');
            $image=$scaled;
        }
        $dest=__DIR__.'/../public/assets/img/uploads/';
        if (!is_dir($dest) && !mkdir($dest,0755,true)) fail('Diretório de imagens indisponível.',500);
        $name='img_'.bin2hex(random_bytes(12)).'.webp';
        $saved=imagewebp($image,$dest.$name,82);
        imagedestroy($image);
        if (!$saved) fail('Falha ao salvar a imagem.',500);
        chmod($dest.$name,0644);
        jsonResponse(['ok'=>true,'image'=>'uploads/'.$name,'url'=>asset('img/uploads/'.$name)],201);
    }
    if ($path==='/api/catalog' && $method==='GET') {
        jsonResponse([
            'settings'=>salon(),
            'categories'=>sql('SELECT * FROM categories ORDER BY sort_order')->fetchAll(),
            'services'=>sql('SELECT sv.*,c.name category FROM services sv JOIN categories c ON c.id=sv.category_id WHERE sv.active=1 ORDER BY c.sort_order,sv.id')->fetchAll(),
            'staff'=>sql('SELECT * FROM staff WHERE active=1 ORDER BY id')->fetchAll(),
            'staff_services'=>sql('SELECT staff_id,service_id FROM staff_services')->fetchAll(),
            'gallery'=>sql('SELECT * FROM gallery WHERE active=1 ORDER BY sort_order,id')->fetchAll(),
            'testimonials'=>sql('SELECT * FROM testimonials WHERE active=1 ORDER BY id')->fetchAll(),
            'client'=>authClient(),
        ]);
    }
    if ($path==='/api/available-days' && $method==='GET') {
        jsonResponse(['days'=>availableDays((int)($_GET['service_id']??0),(int)($_GET['staff_id']??0),(string)($_GET['month']??''))]);
    }
    if ($path==='/api/availability' && $method==='GET') {
        $date=validDate($_GET['date']??null);
        jsonResponse(['date'=>$date,'slots'=>availableSlots((int)($_GET['service_id']??0),(int)($_GET['staff_id']??0),$date)]);
    }
    if ($path==='/api/client/login' && $method==='POST') {
        $data=body();$phone=normalizePhone($data['phone']??null);
        throttle('client',$phone);
        $row=sql('SELECT id,password_hash,active FROM clients WHERE phone=?',[$phone])->fetch();
        $valid=$row && $row['active'] && password_verify((string)($data['password']??''),$row['password_hash']);
        recordAttempt('client',$phone,(bool)$valid);
        if (!$valid) fail('Telefone ou senha incorretos.',401);
        signIn('client',(int)$row['id']);
        jsonResponse(['ok'=>true,'client'=>authClient(),'csrf'=>csrfToken()]);
    }
    if ($path==='/api/client/register' && $method==='POST') {
        $d=body();
        $name=requiredString($d['name']??null,'nome',150);
        $phone=normalizePhone($d['phone']??null);
        $email=validEmail($d['email']??null);
        $hash=passHash(passwordOk($d['password']??null));
        if (empty($d['consent'])) fail('Aceite a política de privacidade para criar sua conta.');
        if (sql('SELECT id FROM clients WHERE phone=?',[$phone])->fetch()) fail('Este telefone já possui uma conta.',409);
        sql('INSERT INTO clients(name,phone,email,password_hash,consent_at) VALUES (?,?,?,?,NOW())',[$name,$phone,$email,$hash]);
        signIn('client',(int)db()->lastInsertId());
        jsonResponse(['ok'=>true,'client'=>authClient(),'csrf'=>csrfToken()],201);
    }
    if ($path==='/api/client/logout' && $method==='POST') { signOut();jsonResponse(['ok'=>true]); }
    if ($path==='/api/bookings' && $method==='POST') {
        $client=authClient();
        $result=makeAppointment(body(),$client?(int)$client['id']:null);
        jsonResponse(['ok'=>true,'booking'=>$result,'redirect'=>url('confirmacao?codigo='.urlencode($result['code']))],201);
    }
    if ($path==='/api/client/bookings' && $method==='GET') {
        $client=requireClient();
        jsonResponse(['bookings'=>sql('SELECT a.id,a.confirmation_code,a.scheduled_date,a.starts,a.ends,a.status,a.price_snapshot,a.duration_minutes,a.notes,sv.name service_name,sv.id service_id,st.name staff_name,st.id staff_id
            FROM appointments a JOIN services sv ON sv.id=a.service_id JOIN staff st ON st.id=a.staff_id WHERE a.client_id=? ORDER BY a.scheduled_date DESC,a.starts DESC',[$client['id']])->fetchAll()]);
    }
    if ($path==='/api/client/profile' && $method==='GET') { jsonResponse(['client'=>requireClient()]); }
    if ($path==='/api/client/profile' && $method==='PUT') {
        $client=requireClient();$d=body();
        $name=requiredString($d['name']??null,'nome',150);
        $phone=normalizePhone($d['phone']??null);
        $email=validEmail($d['email']??null);
        if (sql('SELECT id FROM clients WHERE phone=? AND id<>?',[$phone,$client['id']])->fetch()) fail('Telefone já cadastrado.',409);
        sql('UPDATE clients SET name=?,phone=?,email=? WHERE id=?',[$name,$phone,$email,$client['id']]);
        jsonResponse(['ok'=>true,'client'=>authClient()]);
    }
    if ($path==='/api/client/favorites' && $method==='GET') {
        $client=requireClient();
        jsonResponse([
            'services'=>sql('SELECT s.id,s.name FROM favorite_services f JOIN services s ON s.id=f.service_id WHERE f.client_id=?',[$client['id']])->fetchAll(),
            'staff'=>sql('SELECT s.id,s.name FROM favorite_staff f JOIN staff s ON s.id=f.staff_id WHERE f.client_id=?',[$client['id']])->fetchAll(),
        ]);
    }
    if ($path==='/api/client/favorites' && $method==='POST') {
        $client=requireClient();$d=body();$type=$d['type']??'';$id=(int)($d['id']??0);
        if (!in_array($type,['staff','service'],true) || $id<1) fail('Favorito inválido.');
        $table=$type==='staff'?'favorite_staff':'favorite_services';$col=$type==='staff'?'staff_id':'service_id';$target=$type==='staff'?'staff':'services';
        if (!sql("SELECT 1 FROM $target WHERE id=? AND active=1",[$id])->fetch()) fail('Item não encontrado.',404);
        if (!empty($d['favorite'])) sql("INSERT IGNORE INTO $table(client_id,$col) VALUES (?,?)",[$client['id'],$id]);
        else sql("DELETE FROM $table WHERE client_id=? AND $col=?",[$client['id'],$id]);
        jsonResponse(['ok'=>true]);
    }
    if (preg_match('#^/api/client/bookings/(\d+)/(cancel|reschedule)$#',$path,$m) && $method==='POST') {
        $client=requireClient();
        if ($m[2]==='cancel') clientCancel((int)$m[1],(int)$client['id']);
        else rescheduleAppointment((int)$m[1],body(),(int)$client['id']);
        jsonResponse(['ok'=>true]);
    }
    if ($path==='/api/admin/login' && $method==='POST') {
        $d=body();$email=validEmail($d['email']??null,false);
        throttle('admin',$email);
        $row=sql('SELECT id,password_hash,active FROM admins WHERE email=?',[$email])->fetch();
        $valid=$row && $row['active'] && password_verify((string)($d['password']??''),$row['password_hash']);
        recordAttempt('admin',$email,(bool)$valid);
        if (!$valid) fail('Credenciais inválidas.',401);
        signIn('admin',(int)$row['id']);
        jsonResponse(['ok'=>true,'csrf'=>csrfToken(),'redirect'=>url('admin')]);
    }
    if ($path==='/api/admin/logout' && $method==='POST') { requireAdmin();signOut();jsonResponse(['ok'=>true]); }
    if (str_starts_with($path,'/api/admin/')) adminApiHandle($path,$method);
    fail('Rota não encontrada.',404);
}
