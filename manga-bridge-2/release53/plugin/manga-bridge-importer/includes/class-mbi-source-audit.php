<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** Manual source audit. Image failures never change publication. */
final class MBI_Source_Audit {
 private MBI_Importer $importer;
 private MBI_DB $db;
 private const OPT = 'mbi_source_audit';
 public function __construct( MBI_Importer $importer, MBI_DB $db ) {
  $this->importer=$importer; $this->db=$db;
  add_action('admin_menu',array($this,'menu'));
  add_action('admin_enqueue_scripts',array($this,'assets'));
  add_action('admin_post_mbi_source_audit',array($this,'ajax'));
  add_action('wp_ajax_mbi_source_audit',array($this,'ajax'));
  add_action('pre_get_posts',array($this,'query'));
  add_filter('posts_where',array($this,'where'),10,2);
  add_action('template_redirect',array($this,'access'),1);
  add_filter('wp_insert_post_data',array($this,'publication'),99,2);
 }
 public function menu(): void { add_submenu_page('edit.php?post_type=mbi_manga','Origens indisponíveis','Origens indisponíveis','manage_options','mbi-source-audit',array($this,'render')); }
 public function assets(string $hook): void {
  if(!str_contains($hook,'mbi-source-audit'))return;
  wp_enqueue_style('mbi-source-audit',MBI_URL.'assets/admin.css',array(),MBI_VERSION);
  wp_enqueue_script('mbi-source-audit',MBI_URL.'assets/source-audit.js',array(),MBI_VERSION,true);
  wp_localize_script('mbi-source-audit','MBISource',array('url'=>admin_url('admin-post.php'),'nonce'=>wp_create_nonce('mbi_source_audit')));
 }
 public function render(): void {
  if(!current_user_can('manage_options'))return;
  ?><div class="wrap mbi-wrap" id="mbi-source-audit"><h1>Origens indisponíveis — v<?php echo esc_html(MBI_VERSION); ?></h1>
  <div class="mbi-panel"><p>Verifica a página original de cada mangá publicado, sem testar imagens. Um 404 só é confirmado após duas consultas separadas por pelo menos 30 segundos e com a página inicial da origem respondendo HTTP 200. Redirecionamentos, bloqueios, timeout e demais erros são preservados para revisão.</p>
  <p><button class="button button-primary" data-source="scan">Verificar todas as obras publicadas</button> <button class="button" data-source="resume">Continuar execução</button> <button class="button" data-source="stop">Parar</button></p>
  <p><button class="button button-primary" data-source="withdraw">Retirar do catálogo os 404 confirmados</button></p>
  <p>A verificação não retira nenhuma obra. A retirada coloca o mangá em rascunho e oculta seus capítulos no site, mantendo textos, imagens, capítulos, slugs, categorias e SEO no banco. Há um botão Restaurar em cada obra retirada. Nenhuma alteração acontece apenas por instalar esta versão.</p></div>
  <div class="mbi-panel"><progress id="mbi-source-progress" max="100" value="0" style="width:100%"></progress><p id="mbi-source-status" role="status" aria-live="polite"></p><table class="widefat striped"><thead><tr><th>Mangá</th><th>Página de origem</th><th>Resultado</th><th>Ação</th></tr></thead><tbody id="mbi-source-rows"></tbody></table><p>Mantenha esta página aberta durante a execução. Uma consulta por etapa; quando é necessário confirmar um 404, também verifica a página inicial. Erros são registrados em Logs de erros.</p></div></div><?php
 }
 public function state(): array { if(function_exists('wp_cache_delete'))wp_cache_delete(self::OPT,'options');return (array)get_option(self::OPT,array()); }
 private function running(string $run): bool { $s=$this->state();return !empty($s['active']) && ($s['id']??'')===$run; }
 public function ajax(): void {
  if(!current_user_can('manage_options')){wp_send_json_error(array('message'=>'Acesso negado.'),403);return;}
  check_ajax_referer('mbi_source_audit','nonce');
  try {
   $cmd=sanitize_key($_POST['command']??'status');
   if('scan'===$cmd)$s=$this->start();
   elseif('withdraw'===$cmd)$s=$this->start_withdraw();
   elseif('tick'===$cmd)$s=$this->tick(sanitize_text_field($_POST['run_id']??''));
   elseif('stop'===$cmd){$s=$this->state();$s['active']=false;$s['id']=wp_generate_uuid4();update_option(self::OPT,$s,false);if(($s['mode']??'')==='withdraw')$this->purge();}
   elseif('resume'===$cmd){$s=$this->state();if(empty($s['mode'])||($s['index']??0)>=count($s['ids']??array()))throw new RuntimeException('Inicie uma nova verificação.');$s['active']=true;$s['id']=wp_generate_uuid4();update_option(self::OPT,$s,false);}
   elseif('restore'===$cmd){$this->restore(absint($_POST['manga_id']??0));$s=$this->state();}
   else $s=$this->state();
   unset($s['ids']);wp_send_json_success($s);
  }catch(Throwable $e){wp_send_json_error(array('message'=>$e->getMessage()),400);}
 }
 private function idle(): void {
  if(!empty($this->state()['active']))throw new RuntimeException('Pare ou conclua a execução atual.');
  $n=$this->db->counts();if(!empty($n['processing']) || (!$this->importer->is_paused() && (!empty($n['pending']) || $this->importer->is_serial_active())))throw new RuntimeException('Pause o importador e aguarde o trabalho atual terminar.');
  foreach(array('mbi_media_repair','mbi_cover_refresh','mbi_global_diagnostic') as $key)if(!empty(get_option($key,array())['active']))throw new RuntimeException('Pare o reparo/diagnóstico de imagens antes de verificar as origens.');
 }
 public function start(): array {
  $this->idle();
  $ids=get_posts(array('post_type'=>'mbi_manga','post_status'=>'publish','fields'=>'ids','posts_per_page'=>-1,'orderby'=>'ID','order'=>'ASC'));
  if(!$ids)throw new RuntimeException('Nenhum mangá publicado encontrado.');
  $old=$this->state();$rows=array_filter($old['rows']??array(),static fn($r)=>!empty($r['withdrawn']) || !empty($r['restore_error']));
  $s=array('id'=>wp_generate_uuid4(),'active'=>true,'mode'=>'scan','pass'=>1,'index'=>0,'ids'=>array_map('intval',$ids),'total'=>count($ids),'done'=>0,'rows'=>$rows,'current'=>'Iniciando verificação.');update_option(self::OPT,$s,false);return $s;
 }
 public function start_withdraw(): array {
  $this->idle();$s=$this->state();$ids=array();
  foreach($s['rows']??array() as $id=>$r)if(($r['result']??'')==='404 confirmado' && empty($r['withdrawn']) && (int)($r['confirmed_at']??0)>time()-86400)$ids[]=(int)$id;
  if(!$ids)throw new RuntimeException('Nenhum 404 confirmado nas últimas 24 horas. Execute a verificação novamente.');
  $s=array_merge($s,array('id'=>wp_generate_uuid4(),'active'=>true,'mode'=>'withdraw','index'=>0,'ids'=>$ids,'done'=>0,'total'=>count($ids),'current'=>'Retirando somente os 404 confirmados.'));update_option(self::OPT,$s,false);return $s;
 }
 /** Exact stored source only. No guessed domains, no image requests, no redirects. */
 public function probe(string $url): int {
  $parts=wp_parse_url($url);$allowed=preg_split('/[\r\n,]+/',(string)$this->importer->settings()['allowed_hosts']);$allowed=array_map(static fn($v)=>strtolower(trim($v)),$allowed);
  if(!is_array($parts) || ($parts['scheme']??'')!=='https' || !in_array(strtolower($parts['host']??''),$allowed,true) || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && (int)$parts['port']!==443))throw new RuntimeException('URL ausente ou fora dos domínios autorizados.');
  $r=wp_safe_remote_get($url,array('timeout'=>8,'redirection'=>0,'limit_response_size'=>8192,'headers'=>array('User-Agent'=>'Mozilla/5.0 (compatible; MangaBridgeSourceAudit/1.7.11)','Accept'=>'text/html','Cache-Control'=>'no-cache','Pragma'=>'no-cache')));
  if(is_wp_error($r))throw new RuntimeException($r->get_error_message());return (int)wp_remote_retrieve_response_code($r);
 }
 public function tick( string $run ): array {
		if ( ! MBI_Resource_Guard::begin( 'auditoria', true ) ) { return $this->state(); }
		try { return $this->tick_bounded( $run ); } finally { MBI_Resource_Guard::end(); }
	}

	private function tick_bounded( string $run ): array {
  if(!$this->running($run))return $this->state();
  $token=wp_generate_uuid4();
  if(!add_option('mbi_source_audit_lock',array('token'=>$token,'until'=>time()+30),'',false)){$old=get_option('mbi_source_audit_lock',array());if(($old['until']??0)<time())delete_option('mbi_source_audit_lock');return $this->state();}
  try {
   $s=$this->state();if(!$this->running($run))return $s;
   $id=(int)($s['ids'][$s['index']]??0);
   if(!$id){$s['active']=false;update_option(self::OPT,$s,false);return $s;}
   $source=(string)get_post_meta($id,'_mbi_source_url',true);$s['current']=get_the_title($id);update_option(self::OPT,$s,false);
   try {
    if('withdraw'===$s['mode']){
     $row=$s['rows'][$id]??array();
     if(get_post_type($id)!=='mbi_manga' || get_post_status($id)!=='publish' || $source!==($row['source']??'') || ($row['confirmed_at']??0)<time()-86400)throw new RuntimeException('Registro alterado ou confirmação expirada; preservado.');
     if(404!==$this->probe($source) || 200!==$this->probe('https://'.wp_parse_url($source,PHP_URL_HOST).'/'))throw new RuntimeException('A origem não confirmou novamente o 404; obra preservada.');
     if(!$this->running($run))return $this->state();
     if($source!==(string)get_post_meta($id,'_mbi_source_url',true) || get_post_status($id)!=='publish')throw new RuntimeException('Registro alterado durante confirmação; preservado.');
     $backup=array('source'=>$source,'status'=>'publish','at'=>time());update_post_meta($id,'_mbi_origin_withdrawn',$backup);
     $result=wp_update_post(array('ID'=>$id,'post_status'=>'draft'),true);
     if(is_wp_error($result) || get_post_status($id)!=='draft'){delete_post_meta($id,'_mbi_origin_withdrawn');throw new RuntimeException('Falha ao retirar do catálogo; alteração não confirmada.');}
     $s['rows'][$id]['withdrawn']=true;$s['rows'][$id]['result']='Retirado do catálogo';
     $this->db->log('warning','Obra retirada manualmente após duas respostas HTTP 404 na origem.',array('manga_id'=>$id,'url'=>$source));
    }else{
     if(get_post_type($id)!=='mbi_manga' || get_post_status($id)!=='publish')throw new RuntimeException('Obra deixou de estar publicada; preservada.');
     if(($s['pass']??1)===2 && ($s['rows'][$id]['first_at']??0)>time()-30){$s['current'].=' — aguardando intervalo de confirmação';if($this->running($run))update_option(self::OPT,$s,false);return $s;}
     $code=$this->probe($source);$row=array('id'=>$id,'title'=>get_the_title($id),'source'=>$source,'result'=>'HTTP '.$code.' — preservado','first_at'=>time());
     if(($s['pass']??1)===2 && ($s['rows'][$id]['source']??'')!==$source)throw new RuntimeException('URL alterada entre verificações; preservada.');
     if(404===$code && ($s['pass']??1)===2){$home='https://'.wp_parse_url($source,PHP_URL_HOST).'/';if(200===$this->probe($home)){$row['result']='404 confirmado';$row['confirmed_at']=time();}else $row['result']='Origem indisponível — preservado';}
     elseif(404===$code)$row['result']='404 inicial — aguardando confirmação';
     elseif(200===$code)$row['result']='Origem disponível — preservado';
     if(!$this->running($run))return $this->state();
     if($source!==(string)get_post_meta($id,'_mbi_source_url',true))throw new RuntimeException('URL alterada durante consulta; preservada.');
     $s['rows'][$id]=$row;
    }
   }catch(Throwable $e){if(!$this->running($run))return $this->state();$s['rows'][$id]=array('id'=>$id,'title'=>get_the_title($id),'source'=>$source,'result'=>'Revisar — preservado','detail'=>$e->getMessage());$this->db->log('warning','Auditoria de origem preservou obra.',array('manga_id'=>$id,'url'=>$source,'error'=>$e->getMessage()));}
   if(!$this->running($run))return $this->state();
   $s['index']++;$s['done']=$s['index'];
   if($s['index']>=count($s['ids'])){
    if('scan'===$s['mode'] && ($s['pass']??1)===1){$ids=array();foreach($s['rows'] as $rid=>$r)if(($r['result']??'')==='404 inicial — aguardando confirmação')$ids[]=(int)$rid;$s['ids']=$ids;$s['index']=0;$s['done']=0;$s['total']=count($ids);$s['pass']=2;if(!$ids)$s['active']=false;}
    else $s['active']=false;
   }
   update_option(self::OPT,$s,false);if('withdraw'===$s['mode'] && empty($s['active']))$this->purge();return $s;
  }finally{if((get_option('mbi_source_audit_lock',array())['token']??'')===$token)delete_option('mbi_source_audit_lock');}
 }
 public function restore(int $id): void {
  $this->idle();if(get_post_type($id)!=='mbi_manga' || !current_user_can('edit_post',$id))throw new RuntimeException('Registro inválido.');
  $b=get_post_meta($id,'_mbi_origin_withdrawn',true);if(!$b)throw new RuntimeException('Esta obra não foi retirada por esta função.');
  if(get_post_status($id)!=='draft')throw new RuntimeException('Status alterado manualmente; não restaurado.');
  delete_post_meta($id,'_mbi_origin_withdrawn');$r=wp_update_post(array('ID'=>$id,'post_status'=>'publish'),true);
  if(is_wp_error($r) || get_post_status($id)!=='publish'){update_post_meta($id,'_mbi_origin_withdrawn',$b);throw new RuntimeException('Não foi possível restaurar a obra.');}
  $s=$this->state();if(isset($s['rows'][$id])){$s['rows'][$id]['withdrawn']=false;$s['rows'][$id]['result']='Restaurado manualmente';update_option(self::OPT,$s,false);}$this->purge();$this->db->log('info','Obra restaurada manualmente após retirada por origem 404.',array('manga_id'=>$id));
 }
 public function publication(array $data,array $post): array {
  if(($data['post_status']??'')!=='publish')return $data;
  $id=(int)($post['ID']??0);$parent=(int)($data['post_parent']??0);
  if(($data['post_type']??'')==='mbi_manga' && $id && get_post_meta($id,'_mbi_origin_withdrawn',true))$data['post_status']='draft';
  if(($data['post_type']??'')==='mbi_chapter' && $parent && get_post_meta($parent,'_mbi_origin_withdrawn',true))$data['post_status']='draft';return $data;
 }
 private function public_read(): bool { return !is_admin() || (function_exists('wp_doing_ajax') && wp_doing_ajax() && !current_user_can('edit_posts')); }
 public function query($query): void { if($this->public_read())$query->set('suppress_filters',false); }
 public function where(string $where,$query): string {
  if(!$this->public_read())return $where;global $wpdb;
  return $where." AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} mbi_origin_hidden WHERE mbi_origin_hidden.meta_key='_mbi_origin_withdrawn' AND (mbi_origin_hidden.post_id={$wpdb->posts}.ID OR ({$wpdb->posts}.post_type='mbi_chapter' AND mbi_origin_hidden.post_id={$wpdb->posts}.post_parent)))";
 }
 public function access(): void {
  $id=get_queried_object_id();if(!$id)return;$parent=wp_get_post_parent_id($id);
  if(get_post_meta($id,'_mbi_origin_withdrawn',true) || (get_post_type($id)==='mbi_chapter' && $parent && get_post_meta($parent,'_mbi_origin_withdrawn',true))){global $wp_query;$wp_query->set_404();status_header(404);nocache_headers();}
 }
 private function purge(): void {try{do_action('litespeed_purge_all');if(function_exists('wpo_cache_flush'))wpo_cache_flush();}catch(Throwable $e){$this->db->log('warning','Limpeza de cache após retirada pendente.',array('error'=>$e->getMessage()));} }
}
