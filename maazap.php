<?php
/**
 * Plugin Name: MAAZAP
 * Plugin URI: https://www.instagram.com/jefferson.ornellas
 * Description: Publique uma notícia no seu site e ela vai sozinha para todos os seus grupos de WhatsApp. Você escolhe o formato (texto com prévia do link, foto com legenda ou enquete), monta a mensagem com um modelo pronto e acompanha tudo em um painel com número de grupos, membros e envios. Também dá para enviar manualmente quando quiser e convidar novos membros automaticamente.
 * Version: 3.12.3
 * Author: Jefferson Ornellas
 * Author URI: https://www.instagram.com/jefferson.ornellas
 * Text Domain: maazap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Atualização automática pelo GitHub.
 * Troque pelo seu usuário e repositório. Depois, cada nova versão publicada
 * como "Release" no GitHub aparece como atualização normal no painel do WordPress.
 */
define( 'MAAZAP_GH_USUARIO', 'jeffersonsilva1e11' );
define( 'MAAZAP_GH_REPO', 'maazap' );

class UzAPI_Broadcaster {

	const OPT          = 'uzapi_gn_config';   // configuração + automação
	const OPT_GRUPOS   = 'uzapi_gn_grupos';   // id => ['nome'=>, 'membros'=>]
	const OPT_LOG      = 'uzapi_gn_log';       // histórico de disparos (array)
	const OPT_STATS    = 'uzapi_gn_stats';     // contadores de envio
	const OPT_SNAP     = 'uzapi_gn_snap';      // snapshots diários (crescimento)
	const META_ENVIADO = '_uzapi_gn_enviado';
	const CRON_POST    = 'uzapi_gn_dispatch';
	const CRON_SNAP    = 'uzapi_gn_snapshot';
	const MAX_LOG      = 30;

	/* ===================================================================
	 * BOOT
	 * =================================================================== */

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'registrar_config' ) );
		add_action( 'in_admin_header', array( $this, 'limpar_notices' ), 1000 );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'link_configuracoes' ) );
		add_action( 'transition_post_status', array( $this, 'ao_mudar_status' ), 10, 3 );
		add_action( self::CRON_POST, array( $this, 'disparar_post' ), 10, 1 );
		add_action( self::CRON_SNAP, array( $this, 'cron_snapshot' ) );
		add_action( 'init', array( $this, 'garantir_cron' ) );

		add_action( 'admin_post_uzapi_gn_sync', array( $this, 'acao_sincronizar' ) );
		add_action( 'admin_post_uzapi_gn_testconn', array( $this, 'acao_testar_conexao' ) );
		add_action( 'admin_post_uzapi_gn_manual', array( $this, 'acao_manual' ) );
		add_action( 'admin_post_uzapi_gn_teste', array( $this, 'acao_teste' ) );

		// atualização automática via GitHub
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'checar_atualizacao' ) );
		add_filter( 'plugins_api', array( $this, 'detalhes_atualizacao' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'ajustar_pasta_atualizacao' ), 10, 4 );
	}

	/* ===================================================================
	 * ATUALIZAÇÃO AUTOMÁTICA (GitHub Releases)
	 * =================================================================== */

	private function gh_configurado() {
		return defined( 'MAAZAP_GH_USUARIO' ) && MAAZAP_GH_USUARIO && false === strpos( MAAZAP_GH_USUARIO, 'SEU-USUARIO' );
	}

	private function versao_atual() {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$d = get_plugin_data( __FILE__, false, false );
		return isset( $d['Version'] ) ? $d['Version'] : '0';
	}

	/** Consulta o último Release do GitHub (com cache de 6h para não bater na API a cada page load). */
	private function gh_release() {
		if ( ! $this->gh_configurado() ) {
			return null;
		}
		$cache = get_transient( 'maazap_gh_release' );
		if ( false !== $cache ) {
			return $cache ? $cache : null;
		}
		$r = wp_remote_get(
			'https://api.github.com/repos/' . MAAZAP_GH_USUARIO . '/' . MAAZAP_GH_REPO . '/releases/latest',
			array(
				'timeout' => 15,
				'headers' => array( 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'MAAZAP-Updater' ),
			)
		);
		if ( is_wp_error( $r ) || 200 !== (int) wp_remote_retrieve_response_code( $r ) ) {
			set_transient( 'maazap_gh_release', 0, HOUR_IN_SECONDS ); // evita martelar a API quando falha
			return null;
		}
		$d = json_decode( wp_remote_retrieve_body( $r ), true );
		if ( ! is_array( $d ) || empty( $d['tag_name'] ) ) {
			set_transient( 'maazap_gh_release', 0, HOUR_IN_SECONDS );
			return null;
		}
		set_transient( 'maazap_gh_release', $d, 6 * HOUR_IN_SECONDS );
		return $d;
	}

	/** Prefere o arquivo .zip anexado ao Release; se não houver, usa o zip automático do GitHub. */
	private function gh_download( $rel ) {
		if ( ! empty( $rel['assets'] ) && is_array( $rel['assets'] ) ) {
			foreach ( $rel['assets'] as $a ) {
				if ( ! empty( $a['browser_download_url'] ) && '.zip' === substr( $a['browser_download_url'], -4 ) ) {
					return $a['browser_download_url'];
				}
			}
		}
		return ! empty( $rel['zipball_url'] ) ? $rel['zipball_url'] : '';
	}

	public function checar_atualizacao( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}
		$rel = $this->gh_release();
		if ( ! $rel ) {
			return $transient;
		}
		$nova  = ltrim( (string) $rel['tag_name'], 'vV' );
		$atual = $this->versao_atual();
		if ( version_compare( $nova, $atual, '<=' ) ) {
			return $transient;
		}
		$slug = dirname( plugin_basename( __FILE__ ) );
		$item = array(
			'slug'        => $slug,
			'plugin'      => plugin_basename( __FILE__ ),
			'new_version' => $nova,
			'url'         => 'https://github.com/' . MAAZAP_GH_USUARIO . '/' . MAAZAP_GH_REPO,
			'package'     => $this->gh_download( $rel ),
		);
		$transient->response[ plugin_basename( __FILE__ ) ] = (object) $item;
		return $transient;
	}

	/** Preenche a janela "Ver detalhes da versão" do WordPress. */
	public function detalhes_atualizacao( $res, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || dirname( plugin_basename( __FILE__ ) ) !== $args->slug ) {
			return $res;
		}
		$rel = $this->gh_release();
		if ( ! $rel ) {
			return $res;
		}
		return (object) array(
			'name'          => 'MAAZAP',
			'slug'          => $args->slug,
			'version'       => ltrim( (string) $rel['tag_name'], 'vV' ),
			'author'        => '<a href="https://www.instagram.com/jefferson.ornellas">Jefferson Ornellas</a>',
			'homepage'      => 'https://www.instagram.com/jefferson.ornellas',
			'download_link' => $this->gh_download( $rel ),
			'sections'      => array(
				'description' => 'Envia as notícias do seu site automaticamente para os seus grupos de WhatsApp.',
				'changelog'   => ! empty( $rel['body'] ) ? nl2br( esc_html( $rel['body'] ) ) : 'Sem notas nesta versão.',
			),
		);
	}

	/**
	 * O zip do GitHub vem com um nome de pasta diferente (usuario-repo-hash).
	 * Sem isto, o WordPress instalaria o plugin como se fosse um novo.
	 */
	public function ajustar_pasta_atualizacao( $source, $remote_source, $upgrader, $args = array() ) {
		global $wp_filesystem;
		if ( empty( $args['plugin'] ) || plugin_basename( __FILE__ ) !== $args['plugin'] ) {
			return $source;
		}
		$destino = trailingslashit( $remote_source ) . dirname( plugin_basename( __FILE__ ) );
		if ( trailingslashit( $source ) === trailingslashit( $destino ) ) {
			return $source;
		}
		if ( $wp_filesystem && $wp_filesystem->move( $source, $destino, true ) ) {
			return trailingslashit( $destino );
		}
		return $source;
	}

	public static function ativar() {
		if ( ! wp_next_scheduled( self::CRON_SNAP ) ) {
			wp_schedule_event( time() + 120, 'daily', self::CRON_SNAP );
		}
	}

	public static function desativar() {
		wp_clear_scheduled_hook( self::CRON_SNAP );
	}

	public function garantir_cron() {
		if ( ! wp_next_scheduled( self::CRON_SNAP ) ) {
			wp_schedule_event( time() + 120, 'daily', self::CRON_SNAP );
		}
	}

	public function cron_snapshot() {
		if ( $this->configurado() ) {
			$this->sincronizar_grupos();
		}
		$this->snapshot();
	}

	/** Atalho "Configurações" na lista de plugins. */
	public function link_configuracoes( $links ) {
		$atalho = '<a href="' . esc_url( admin_url( 'options-general.php?page=uzapi-gn' ) ) . '">Configurações</a>';
		array_unshift( $links, $atalho );
		return $links;
	}

	/** Remove avisos de terceiros SÓ na nossa página (deixa o resto do admin intacto). */
	public function limpar_notices() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$s = get_current_screen();
		if ( $s && 'settings_page_uzapi-gn' === $s->id ) {
			remove_all_actions( 'admin_notices' );
			remove_all_actions( 'all_admin_notices' );
			remove_all_actions( 'user_admin_notices' );
			remove_all_actions( 'network_admin_notices' );
		}
	}

	/* ===================================================================
	 * CONFIG / HTTP
	 * =================================================================== */

	private function cfg( $k = null, $d = '' ) {
		$c = get_option( self::OPT, array() );
		if ( null === $k ) {
			return $c;
		}
		return isset( $c[ $k ] ) ? $c[ $k ] : $d;
	}

	private function base_url() {
		return sprintf(
			'https://api.uzapi.com.br/%s/%s/%s',
			rawurlencode( $this->cfg( 'username' ) ),
			rawurlencode( $this->cfg( 'version', 'v1' ) ),
			rawurlencode( $this->cfg( 'phone_number_id' ) )
		);
	}

	private function headers() {
		return array(
			'Authorization' => 'Bearer ' . $this->cfg( 'token' ),
			'Content-Type'  => 'application/json',
		);
	}

	private function configurado() {
		return $this->cfg( 'username' ) && $this->cfg( 'phone_number_id' ) && $this->cfg( 'token' );
	}

	private function post_api( $rota, $body ) {
		$r = wp_remote_post(
			$this->base_url() . $rota,
			array( 'headers' => $this->headers(), 'body' => wp_json_encode( $body ), 'timeout' => 25 )
		);
		if ( is_wp_error( $r ) ) {
			return array( 'ok' => false, 'msg' => 'ERRO: ' . $r->get_error_message(), 'code' => 0, 'raw' => '' );
		}
		$code = wp_remote_retrieve_response_code( $r );
		$raw  = wp_remote_retrieve_body( $r );
		return array( 'ok' => ( $code >= 200 && $code < 300 ), 'msg' => "HTTP {$code} {$raw}", 'code' => $code, 'raw' => $raw );
	}

	/* ===================================================================
	 * ESTATÍSTICAS
	 * =================================================================== */

	private function registrar_envio( $ok ) {
		$s = get_option( self::OPT_STATS, array() );
		$s['total']   = ( $s['total'] ?? 0 ) + 1;
		$chave        = $ok ? 'sucesso' : 'falha';
		$s[ $chave ]  = ( $s[ $chave ] ?? 0 ) + 1;
		$hoje         = current_time( 'Y-m-d' );
		if ( ! isset( $s['por_dia'] ) || ! is_array( $s['por_dia'] ) ) {
			$s['por_dia'] = array();
		}
		$s['por_dia'][ $hoje ] = ( $s['por_dia'][ $hoje ] ?? 0 ) + 1;
		if ( count( $s['por_dia'] ) > 140 ) {
			$s['por_dia'] = array_slice( $s['por_dia'], -140, null, true );
		}
		$s['ult_envio'] = current_time( 'mysql' );
		update_option( self::OPT_STATS, $s );
	}

	private function serie_dias( $por_dia, $n ) {
		$por_dia = is_array( $por_dia ) ? $por_dia : array();
		$base    = current_time( 'Y-m-d' );
		$out     = array();
		for ( $i = $n - 1; $i >= 0; $i-- ) {
			$d         = gmdate( 'Y-m-d', strtotime( "$base -$i days" ) );
			$out[ $d ] = (int) ( $por_dia[ $d ] ?? 0 );
		}
		return $out;
	}

	private function envios_por_dia( $n ) {
		$s = get_option( self::OPT_STATS, array() );
		return $this->serie_dias( $s['por_dia'] ?? array(), $n );
	}

	/* ===================================================================
	 * ENVIO (texto / imagem / enquete)
	 * =================================================================== */

	private function enviar_conteudo( $to, $conteudo, $delay = 0 ) {
		$formato = $conteudo['formato'] ?? 'texto';
		$texto   = $conteudo['texto'] ?? '';
		$imagem  = $conteudo['imagem'] ?? '';

		if ( 'poll' === $formato ) {
			$payload = array(
				'type'         => 'poll',
				'groupId'      => $to,
				'delayMessage' => (int) $delay,
				'poll'         => array(
					'question'             => $conteudo['pergunta'] ?? '',
					'options'              => array_values( (array) ( $conteudo['opcoes'] ?? array() ) ),
					'maxSelectableOptions' => max( 1, (int) ( $conteudo['max'] ?? 1 ) ),
				),
			);
		} elseif ( 'imagem' === $formato && ( $imagem || ! empty( $conteudo['imagem_id'] ) ) ) {
			// preferimos o ID (upload direto): não depende da API conseguir baixar a URL
			$img = array( 'caption' => $texto );
			if ( ! empty( $conteudo['imagem_id'] ) ) {
				$img['id'] = $conteudo['imagem_id'];
			} else {
				$img['link'] = $imagem;
			}
			$payload = array(
				'to'           => $to,
				'type'         => 'image',
				'delayMessage' => (int) $delay,
				'image'        => $img,
			);
		} else {
			$payload = array(
				'to'           => $to,
				'type'         => 'text',
				'delayMessage' => (int) $delay,
				// preview_url ativa a prévia do link (vai DENTRO do objeto text)
				'text'         => array( 'preview_url' => true, 'body' => $texto ),
			);
		}

		if ( ! empty( $conteudo['mencionar_todos'] ) && 'poll' !== $formato ) {
			$payload['mentionedJID'] = array( 'all' );
		}

		$res = $this->post_api( '/messages', $payload );
		$this->registrar_envio( $res['ok'] );
		return $res['msg'];
	}

	private function enviar_para_grupos( array $grupos, $conteudo ) {
		$base      = (int) $this->cfg( 'delay', 5 );
		$jitter    = (int) $this->cfg( 'jitter', 3 );
		$delay_min = (int) $this->cfg( 'delay_min', 3 );
		$linhas    = array();
		$i         = 0;

		$ehPoll  = 'poll' === ( $conteudo['formato'] ?? 'texto' );
		$crescer = ! empty( $conteudo['crescimento'] ) && ! $ehPoll;

		// UTM: aplica uma vez (é igual para todos os grupos)
		if ( ! $ehPoll ) {
			$conteudo['texto'] = $this->aplicar_utm( (string) ( $conteudo['texto'] ?? '' ) );
		}

		// imagem: sobe UMA vez pra API e reusa o id em todos os grupos
		if ( 'imagem' === ( $conteudo['formato'] ?? '' ) && empty( $conteudo['imagem_id'] ) && ! empty( $conteudo['imagem'] ) ) {
			$mid = $this->upload_imagem( $conteudo['imagem'], $conteudo['imagem_path'] ?? '' );
			if ( $mid ) {
				$conteudo['imagem_id'] = $mid;
			}
		}

		foreach ( $grupos as $gid ) {
			$c = $conteudo;
			// crescimento orgânico: anexa o link de convite do próprio grupo
			if ( $crescer ) {
				$link = $this->link_grupo( $gid );
				if ( $link ) {
					$rotulo     = $this->cfg( 'crescimento_texto', '📩 Convide amigos para o grupo:' );
					$c['texto'] = rtrim( (string) ( $c['texto'] ?? '' ) ) . "\n\n" . $rotulo . "\n" . $link;
				}
			}
			// delay mínimo em toda mensagem (dá tempo do preview ser gerado antes do envio)
			$delay    = max( $delay_min, ( $base * $i ) + ( $jitter > 0 ? wp_rand( 0, $jitter ) : 0 ) );
			$msg      = $this->enviar_conteudo( $gid, $c, $delay );
			$nome     = $this->nome_grupo( $gid );
			$linhas[] = sprintf( '[%s] %s', $nome ? $nome : $gid, $msg );
			$i++;
		}
		return $linhas;
	}

	/* ===================================================================
	 * AUTOMAÇÃO
	 * =================================================================== */

	public function ao_mudar_status( $novo, $antigo, $post ) {
		if ( 'publish' !== $novo || 'publish' === $antigo ) {
			return;
		}
		$tipos = (array) $this->cfg( 'post_types', array( 'post' ) );
		if ( ! in_array( $post->post_type, $tipos, true ) ) {
			return;
		}
		if ( get_post_meta( $post->ID, self::META_ENVIADO, true ) ) {
			return;
		}
		if ( ! $this->cfg( 'ativo' ) || ! $this->configurado() ) {
			return;
		}
		update_post_meta( $post->ID, self::META_ENVIADO, 'agendado' );
		if ( ! wp_next_scheduled( self::CRON_POST, array( $post->ID ) ) ) {
			wp_schedule_single_event( time() + 5, self::CRON_POST, array( $post->ID ) );
		}
	}

	public function disparar_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return;
		}
		$grupos = $this->grupos_ids();
		if ( empty( $grupos ) ) {
			$this->log( 'AUTO', "Post #{$post_id}: nenhum grupo. Rode 'Sincronizar grupos'." );
			return;
		}
		$conteudo = $this->conteudo_do_post( $post );
		$linhas   = $this->enviar_para_grupos( $grupos, $conteudo );
		update_post_meta( $post_id, self::META_ENVIADO, 'enviado' );
		$this->log( 'AUTO', "Post #{$post_id} \"" . get_the_title( $post ) . "\" → " . count( $grupos ) . ' grupos', $linhas );
	}

	private function conteudo_do_post( $post ) {
		$formato = $this->cfg( 'formato', 'texto' );
		$texto   = $this->render_template( $this->cfg( 'template', "📰 *{titulo}*\n\n{link}" ), $post );
		$imagem  = '';
		$caminho = '';
		if ( 'imagem' === $formato ) {
			$imagem = get_the_post_thumbnail_url( $post, 'large' );
			if ( ! $imagem ) {
				$formato = 'texto';
			} else {
				// caminho local da imagem original: upload direto, sem depender de baixar a URL
				$tid     = get_post_thumbnail_id( $post );
				$caminho = $tid ? get_attached_file( $tid ) : '';
			}
		}
		return array(
			'formato'         => $formato,
			'texto'           => $texto,
			'imagem'          => $imagem,
			'imagem_path'     => $caminho,
			'mencionar_todos' => (bool) $this->cfg( 'mencionar_todos', 0 ),
			'crescimento'     => (bool) $this->cfg( 'crescimento', 0 ),
		);
	}

	private function render_template( $tpl, $post ) {
		$cats = get_the_category( $post->ID );
		$vars = array(
			'{titulo}'    => get_the_title( $post ),
			'{link}'      => get_permalink( $post ),
			'{resumo}'    => $this->resumo( $post ),
			'{categoria}' => ! empty( $cats ) ? $cats[0]->name : '',
			'{autor}'     => get_the_author_meta( 'display_name', $post->post_author ),
			'{data}'      => get_the_date( '', $post ),
			'{site}'      => get_bloginfo( 'name' ),
		);
		return trim( strtr( $tpl, $vars ) );
	}

	private function resumo( $post ) {
		$e = has_excerpt( $post ) ? get_the_excerpt( $post ) : wp_strip_all_tags( $post->post_content );
		return wp_trim_words( $e, 30, '…' );
	}

	/* ===================================================================
	 * GRUPOS
	 * =================================================================== */

	/** id => ['nome'=>string,'membros'=>int|null] — com migração de formatos antigos. */
	private function grupos_map() {
		$g = get_option( self::OPT_GRUPOS, array() );
		if ( ! is_array( $g ) ) {
			return array();
		}
		$out = array();
		foreach ( $g as $id => $v ) {
			if ( is_array( $v ) ) {
				$out[ $id ] = array(
					'nome'    => $v['nome'] ?? (string) $id,
					'membros' => isset( $v['membros'] ) ? $v['membros'] : null,
					'link'    => isset( $v['link'] ) ? $v['link'] : null,
				);
			} elseif ( is_int( $id ) ) {
				$out[ $v ] = array( 'nome' => (string) $v, 'membros' => null, 'link' => null );
			} else {
				$out[ $id ] = array( 'nome' => (string) $v, 'membros' => null, 'link' => null );
			}
		}
		return $out;
	}

	private function grupos_ids() {
		return array_keys( $this->grupos_map() );
	}

	private function nome_grupo( $id ) {
		$m = $this->grupos_map();
		return isset( $m[ $id ] ) ? $m[ $id ]['nome'] : '';
	}

	private function link_grupo( $id ) {
		$m = $this->grupos_map();
		return isset( $m[ $id ]['link'] ) ? $m[ $id ]['link'] : null;
	}

	private function total_membros() {
		$total  = 0;
		$conhec = false;
		foreach ( $this->grupos_map() as $g ) {
			if ( null !== $g['membros'] ) {
				$total += (int) $g['membros'];
				$conhec = true;
			}
		}
		return $conhec ? $total : null;
	}

	private function sincronizar_grupos( &$raw = null, $com_membros = true ) {
		$res  = $this->post_api( '/groups', array( 'type' => 'getAllGroups' ) );
		$raw  = $res['raw'] ? $res['raw'] : $res['msg'];
		$mapa = array();

		$data = json_decode( $res['raw'], true );
		if ( is_array( $data ) ) {
			$this->coletar_grupos( $data, $mapa );
		}
		if ( empty( $mapa ) && preg_match_all( '/[\w\-]+@g\.us/', (string) $res['raw'], $m ) ) {
			foreach ( array_unique( $m[0] ) as $id ) {
				$mapa[ $id ] = array( 'nome' => $id, 'membros' => null, 'link' => null );
			}
		}
		if ( empty( $mapa ) ) {
			return $mapa;
		}

		// getAllGroups não traz membros nem link: preserva o que já conhecemos
		$antigo = $this->grupos_map();
		foreach ( $mapa as $id => $g ) {
			if ( null === $g['membros'] && isset( $antigo[ $id ]['membros'] ) && null !== $antigo[ $id ]['membros'] ) {
				$mapa[ $id ]['membros'] = $antigo[ $id ]['membros'];
			}
			if ( empty( $g['link'] ) && ! empty( $antigo[ $id ]['link'] ) ) {
				$mapa[ $id ]['link'] = $antigo[ $id ]['link'];
			}
		}
		update_option( self::OPT_GRUPOS, $mapa );

		// enriquece cada grupo: nº de membros (metadata) e link de convite (se crescimento ligado)
		if ( $com_membros ) {
			$this->enriquecer_grupos();
		}
		return $this->grupos_map();
	}

	/** Busca nº de membros (sempre) e link de convite (se crescimento orgânico ligado) por grupo. */
	private function enriquecer_grupos() {
		$mapa       = $this->grupos_map();
		$inicio     = time();
		$orcamento  = 25; // segundos: evita estourar max_execution_time
		$mudou      = false;
		$buscar_link = true; // sempre captura o convite: assim ligar "crescimento" funciona na hora
		foreach ( $mapa as $id => $g ) {
			if ( ( time() - $inicio ) > $orcamento ) {
				break;
			}
			$n = $this->membros_do_grupo( $id );
			if ( null !== $n ) {
				$mapa[ $id ]['membros'] = $n;
				$mudou                  = true;
			}
			if ( $buscar_link ) {
				$l = $this->link_do_grupo( $id );
				if ( $l ) {
					$mapa[ $id ]['link'] = $l;
					$mudou               = true;
				}
			}
		}
		if ( $mudou ) {
			update_option( self::OPT_GRUPOS, $mapa );
		}
	}

	private function membros_do_grupo( $gid ) {
		$res = $this->post_api( '/groups', array( 'type' => 'metadata', 'groupId' => $gid ) );
		return $this->extrair_membros( json_decode( $res['raw'], true ) );
	}

	private function link_do_grupo( $gid ) {
		$res = $this->post_api( '/groups', array( 'type' => 'invite', 'action' => 'get', 'groupId' => $gid ) );
		return $this->extrair_link( json_decode( $res['raw'], true ) );
	}

	/** Varre a resposta de invite/get procurando o link chat.whatsapp.com. */
	private function extrair_link( $node ) {
		if ( ! is_array( $node ) ) {
			return null;
		}
		foreach ( $node as $k => $v ) {
			if ( is_string( $k ) && 'link' === strtolower( $k ) && is_string( $v ) && false !== strpos( $v, 'chat.whatsapp.com' ) ) {
				return $v;
			}
		}
		foreach ( $node as $v ) {
			if ( is_array( $v ) ) {
				$r = $this->extrair_link( $v );
				if ( $r ) {
					return $r;
				}
			}
		}
		return null;
	}

	/**
	 * Varre a resposta de metadata procurando o nº de participantes.
	 * A uzAPI retorna a lista na chave "Participants" (P maiúsculo), sem "size" —
	 * por isso a comparação de chaves é case-insensitive.
	 */
	private function extrair_membros( $node ) {
		if ( ! is_array( $node ) ) {
			return null;
		}
		// 1) array de participantes (Participants / participants, qualquer caixa)
		foreach ( $node as $k => $v ) {
			if ( is_string( $k ) && 'participants' === strtolower( $k ) && is_array( $v ) ) {
				return count( $v );
			}
		}
		// 2) campo numérico de contagem, se existir
		$contagens = array( 'size', 'participantscount', 'membercount', 'participantcount', 'totalparticipants' );
		foreach ( $node as $k => $v ) {
			if ( is_string( $k ) && is_numeric( $v ) && in_array( strtolower( $k ), $contagens, true ) ) {
				return (int) $v;
			}
		}
		// 3) desce na estrutura (a resposta vem aninhada em data.data)
		foreach ( $node as $v ) {
			if ( is_array( $v ) ) {
				$r = $this->extrair_membros( $v );
				if ( null !== $r ) {
					return $r;
				}
			}
		}
		return null;
	}

	private function coletar_grupos( $node, array &$mapa ) {
		if ( ! is_array( $node ) ) {
			return;
		}
		$id = null;
		foreach ( array( 'id', 'jid', 'gid', 'groupId', 'group_id', 'chatId', 'remoteJid' ) as $k ) {
			if ( ! empty( $node[ $k ] ) && is_string( $node[ $k ] ) && false !== strpos( $node[ $k ], '@g.us' ) ) {
				$id = $node[ $k ];
				break;
			}
		}
		if ( $id ) {
			$nome = '';
			foreach ( array( 'subject', 'name', 'title', 'groupName', 'subjectName' ) as $k ) {
				if ( ! empty( $node[ $k ] ) && is_string( $node[ $k ] ) ) {
					$nome = $node[ $k ];
					break;
				}
			}
			$membros = null;
			foreach ( array( 'size', 'participantsCount', 'memberCount', 'participantCount', 'totalParticipants' ) as $k ) {
				if ( isset( $node[ $k ] ) && is_numeric( $node[ $k ] ) ) {
					$membros = (int) $node[ $k ];
					break;
				}
			}
			if ( null === $membros && isset( $node['participants'] ) && is_array( $node['participants'] ) ) {
				$membros = count( $node['participants'] );
			}
			$mapa[ $id ] = array( 'nome' => $nome ? $nome : $id, 'membros' => $membros, 'link' => null );
		}
		foreach ( $node as $v ) {
			if ( is_array( $v ) ) {
				$this->coletar_grupos( $v, $mapa );
			}
		}
	}

	/* ===================================================================
	 * SNAPSHOT (crescimento)
	 * =================================================================== */

	private function snapshot() {
		$snaps = get_option( self::OPT_SNAP, array() );
		if ( ! is_array( $snaps ) ) {
			$snaps = array();
		}
		$hoje  = current_time( 'Y-m-d' );
		$ponto = array( 'data' => $hoje, 'grupos' => count( $this->grupos_ids() ), 'membros' => $this->total_membros() );
		$snaps = array_values( array_filter( $snaps, function ( $x ) use ( $hoje ) {
			return ( $x['data'] ?? '' ) !== $hoje;
		} ) );
		$snaps[] = $ponto;
		update_option( self::OPT_SNAP, array_slice( $snaps, -90 ) );
	}

	/* ===================================================================
	 * AÇÕES ADMIN
	 * =================================================================== */

	private function guard( $nonce ) {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( $nonce ) ) {
			wp_die( 'Sem permissão.' );
		}
	}

	private function redir( $tab, $extra = '' ) {
		$url = admin_url( 'options-general.php?page=uzapi-gn&tab=' . $tab );
		wp_safe_redirect( $extra ? $url . '&' . $extra : $url );
		exit;
	}

	public function acao_testar_conexao() {
		$this->guard( 'uzapi_gn_testconn' );
		if ( ! $this->configurado() ) {
			set_transient( 'uzapi_gn_conn', "Suas credenciais ainda não foram salvas.\n\nPreencha usuário, ID da instância e token de acesso e clique em “Salvar alterações”. Só depois use os botões — eles usam os dados já salvos, não o que está digitado na tela.", 120 );
			$this->redir( 'config', 'conn=1' );
		}
		$r   = wp_remote_get( $this->base_url() . '/instance', array( 'headers' => $this->headers(), 'timeout' => 20 ) );
		$msg = is_wp_error( $r ) ? 'ERRO: ' . $r->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $r ) . ' ' . wp_remote_retrieve_body( $r );
		set_transient( 'uzapi_gn_conn', $msg, 120 );
		$this->redir( 'config', 'conn=1' );
	}

	public function acao_sincronizar() {
		$this->guard( 'uzapi_gn_sync' );
		if ( ! $this->configurado() ) {
			set_transient( 'uzapi_gn_sync_raw', 'Suas credenciais ainda não foram salvas. Preencha os dados de conexão e clique em “Salvar alterações” antes de sincronizar.', 120 );
			set_transient( 'uzapi_gn_sync_count', 0, 120 );
			set_transient( 'uzapi_gn_sync_membros', 0, 120 );
			$this->redir( 'config', 'sincronizado=1' );
		}
		$raw  = '';
		$mapa = $this->sincronizar_grupos( $raw );
		$comMembros = count( array_filter( $mapa, function ( $g ) {
			return null !== $g['membros'];
		} ) );
		set_transient( 'uzapi_gn_sync_raw', $raw, 120 );
		set_transient( 'uzapi_gn_sync_count', count( $mapa ), 120 );
		set_transient( 'uzapi_gn_sync_membros', $comMembros, 120 );
		$this->redir( 'config', 'sincronizado=1' );
	}

	public function acao_manual() {
		$this->guard( 'uzapi_gn_manual' );
		if ( ! $this->configurado() ) {
			$this->redir( 'manual', 'erro=config' );
		}
		$grupos = isset( $_POST['grupos'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['grupos'] ) ) : array();
		if ( empty( $grupos ) ) {
			$this->redir( 'manual', 'erro=grupos' );
		}
		$tipo   = sanitize_key( $_POST['tipo'] ?? 'texto' );
		$mencao = ! empty( $_POST['mencionar_todos'] );

		if ( 'poll' === $tipo ) {
			$pergunta = sanitize_text_field( wp_unslash( $_POST['pergunta'] ?? '' ) );
			$linhas   = preg_split( '/\r\n|\r|\n/', sanitize_textarea_field( wp_unslash( $_POST['opcoes'] ?? '' ) ) );
			$opcoes   = array_values( array_filter( array_map( 'trim', (array) $linhas ) ) );
			if ( '' === $pergunta || count( $opcoes ) < 2 ) {
				$this->redir( 'manual', 'erro=poll' );
			}
			$conteudo = array( 'formato' => 'poll', 'pergunta' => $pergunta, 'opcoes' => $opcoes, 'max' => max( 1, (int) ( $_POST['max'] ?? 1 ) ) );
		} else {
			$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
			$texto   = isset( $_POST['mensagem'] ) ? sanitize_textarea_field( wp_unslash( $_POST['mensagem'] ) ) : '';
			$imagem  = isset( $_POST['imagem'] ) ? esc_url_raw( wp_unslash( $_POST['imagem'] ) ) : '';

			if ( $post_id && ( $p = get_post( $post_id ) ) ) {
				if ( '' === trim( $texto ) ) {
					$texto = $this->render_template( $this->cfg( 'template', "📰 *{titulo}*\n\n{link}" ), $p );
				}
				if ( '' === $imagem ) {
					$imagem = get_the_post_thumbnail_url( $p, 'large' ) ?: '';
				}
			}

			if ( 'imagem' === $tipo ) {
				if ( '' === $imagem ) {
					$this->redir( 'manual', 'erro=imagem' );
				}
				// se veio de uma notícia, usa o arquivo local (upload direto)
				$caminho = '';
				if ( $post_id && ( $tid = get_post_thumbnail_id( $post_id ) ) ) {
					$caminho = get_attached_file( $tid );
				}
				$conteudo = array( 'formato' => 'imagem', 'texto' => $texto, 'imagem' => $imagem, 'imagem_path' => $caminho );
			} else {
				if ( '' === trim( $texto ) ) {
					$this->redir( 'manual', 'erro=msg' );
				}
				$conteudo = array( 'formato' => 'texto', 'texto' => $texto );
			}
			$conteudo['mencionar_todos'] = $mencao;
			$conteudo['crescimento']     = ! empty( $_POST['crescimento'] );
		}

		$linhas = $this->enviar_para_grupos( $grupos, $conteudo );
		$this->log( 'MANUAL', count( $grupos ) . ' grupo(s) • ' . $tipo, $linhas );
		set_transient( 'uzapi_gn_manual_count', count( $grupos ), 120 );
		$this->redir( 'manual', 'enviado=1' );
	}

	public function acao_teste() {
		$this->guard( 'uzapi_gn_teste' );
		if ( ! $this->configurado() ) {
			$this->redir( 'teste', 'erro=config' );
		}
		$numero = isset( $_POST['numero'] ) ? preg_replace( '/\D/', '', wp_unslash( $_POST['numero'] ) ) : '';
		$msg    = isset( $_POST['mensagem'] ) ? sanitize_textarea_field( wp_unslash( $_POST['mensagem'] ) ) : '';
		$img    = isset( $_POST['imagem'] ) ? esc_url_raw( wp_unslash( $_POST['imagem'] ) ) : '';
		if ( '' === $numero || '' === trim( $msg ) ) {
			$this->redir( 'teste', 'erro=campos' );
		}
		$conteudo = array( 'formato' => $img ? 'imagem' : 'texto', 'texto' => $this->aplicar_utm( $msg ), 'imagem' => $img );
		if ( $img ) {
			$mid = $this->upload_imagem( $img );
			if ( $mid ) {
				$conteudo['imagem_id'] = $mid; // mesmo caminho do envio real
			}
		}
		$res = $this->enviar_conteudo( $numero, $conteudo, (int) $this->cfg( 'delay_min', 3 ) );
		$this->log( 'TESTE', $numero, array( $res ) );
		set_transient( 'uzapi_gn_teste_res', $res, 120 );
		$this->redir( 'teste', 'enviado=1' );
	}

	/**
	 * Faz upload da imagem para a uzAPI e devolve o media id.
	 * Lê do disco quando a imagem é local (mais rápido e imune a WAF/anti-bot);
	 * senão baixa a URL usando User-Agent de navegador.
	 */
	private function upload_imagem( $url, $path = '' ) {
		$bytes = '';
		$nome  = 'imagem.jpg';
		$mime  = 'image/jpeg';

		if ( $path && @file_exists( $path ) ) {
			$bytes = @file_get_contents( $path );
			$nome  = basename( $path );
			$tipo  = wp_check_filetype( $nome );
			if ( ! empty( $tipo['type'] ) ) {
				$mime = $tipo['type'];
			}
		} elseif ( $url ) {
			$r = wp_remote_get( $url, array(
				'timeout' => 30,
				'headers' => array( 'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36' ),
			) );
			if ( is_wp_error( $r ) || 200 !== (int) wp_remote_retrieve_response_code( $r ) ) {
				return '';
			}
			$bytes = wp_remote_retrieve_body( $r );
			$ct    = wp_remote_retrieve_header( $r, 'content-type' );
			if ( $ct && false !== strpos( $ct, 'image/' ) ) {
				$mime = $ct;
			}
			$b = basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
			if ( $b ) {
				$nome = $b;
			}
		}

		if ( '' === $bytes ) {
			return '';
		}

		$boundary = wp_generate_password( 24, false );
		$body     = '--' . $boundary . "\r\n";
		$body    .= 'Content-Disposition: form-data; name="file"; filename="' . $nome . '"' . "\r\n";
		$body    .= 'Content-Type: ' . $mime . "\r\n\r\n";
		$body    .= $bytes . "\r\n";
		$body    .= '--' . $boundary . "\r\n";
		$body    .= 'Content-Disposition: form-data; name="messaging_product"' . "\r\n\r\n";
		$body    .= "whatsapp\r\n";
		$body    .= '--' . $boundary . "--\r\n";

		$r = wp_remote_post( $this->base_url() . '/media', array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->cfg( 'token' ),
				'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
			),
			'body'    => $body,
			'timeout' => 60,
		) );
		if ( is_wp_error( $r ) ) {
			return '';
		}
		$d = json_decode( wp_remote_retrieve_body( $r ), true );
		return $this->extrair_media_id( $d );
	}

	/** Acha o id devolvido pelo /media (resposta: {"id":"211080794854514"}). */
	private function extrair_media_id( $node ) {
		if ( ! is_array( $node ) ) {
			return '';
		}
		if ( ! empty( $node['id'] ) && is_scalar( $node['id'] ) ) {
			return (string) $node['id'];
		}
		foreach ( $node as $v ) {
			if ( is_array( $v ) ) {
				$r = $this->extrair_media_id( $v );
				if ( $r ) {
					return $r;
				}
			}
		}
		return '';
	}

	/** Anexa utm_source=grupos a cada link do site na mensagem (ignora convite do WhatsApp). */
	private function aplicar_utm( $texto ) {
		if ( ! preg_match_all( '#https?://[^\s<>"\']+#i', (string) $texto, $m ) ) {
			return $texto;
		}
		foreach ( array_unique( $m[0] ) as $bruto ) {
			$url  = rtrim( $bruto, '.,;:!?)"\'' ); // ignora pontuação colada no fim
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			if ( false !== strpos( $host, 'chat.whatsapp.com' ) ) {
				continue; // não mexe no link de convite
			}
			if ( false !== stripos( $url, 'utm_source=' ) ) {
				continue; // já tem utm
			}
			$sep    = ( false !== strpos( $url, '?' ) ) ? '&' : '?';
			$texto  = str_replace( $url, $url . $sep . 'utm_source=grupos', $texto );
		}
		return $texto;
	}

	private function log( $tipo, $resumo, $linhas = array() ) {
		$logs = get_option( self::OPT_LOG, array() );
		if ( ! is_array( $logs ) ) {
			$logs = array();
		}
		array_unshift( $logs, array( 'quando' => current_time( 'mysql' ), 'tipo' => $tipo, 'resumo' => $resumo, 'linhas' => $linhas ) );
		update_option( self::OPT_LOG, array_slice( $logs, 0, self::MAX_LOG ) );
	}

	/* ===================================================================
	 * ADMIN UI
	 * =================================================================== */

	public function menu() {
		add_options_page( 'MAAZAP', 'MAAZAP', 'manage_options', 'uzapi-gn', array( $this, 'pagina' ) );
	}

	public function registrar_config() {
		register_setting( 'uzapi_gn', self::OPT, array( $this, 'sanitizar' ) );
	}

	public function sanitizar( $in ) {
		$formato = $in['formato'] ?? 'texto';
		return array(
			'ativo'           => ! empty( $in['ativo'] ) ? 1 : 0,
			'username'        => sanitize_text_field( $in['username'] ?? '' ),
			'version'         => sanitize_text_field( $in['version'] ?? 'v1' ),
			'phone_number_id' => sanitize_text_field( $in['phone_number_id'] ?? '' ),
			'token'           => sanitize_text_field( $in['token'] ?? '' ),
			'formato'         => in_array( $formato, array( 'texto', 'imagem' ), true ) ? $formato : 'texto',
			'delay'           => max( 0, (int) ( $in['delay'] ?? 5 ) ),
			'jitter'          => max( 0, (int) ( $in['jitter'] ?? 3 ) ),
			'delay_min'       => max( 0, (int) ( $in['delay_min'] ?? 3 ) ),
			'mencionar_todos' => ! empty( $in['mencionar_todos'] ) ? 1 : 0,
			'crescimento'       => ! empty( $in['crescimento'] ) ? 1 : 0,
			'crescimento_texto' => sanitize_text_field( $in['crescimento_texto'] ?? '📩 Convide amigos para o grupo:' ),
			'template'        => wp_kses_post( $in['template'] ?? "📰 *{titulo}*\n\n{link}" ),
			'post_types'      => ! empty( $in['post_types'] ) ? array_map( 'sanitize_key', (array) $in['post_types'] ) : array( 'post' ),
		);
	}

	private function tab() {
		$t = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'dashboard';
		return in_array( $t, array( 'dashboard', 'config', 'manual', 'teste', 'logs' ), true ) ? $t : 'dashboard';
	}

	public function pagina() {
		$tab  = $this->tab();
		$tabs = array( 'dashboard' => 'Dashboard', 'config' => 'Configurações', 'manual' => 'Envio manual', 'teste' => 'Teste', 'logs' => 'Logs' );
		echo $this->css();
		echo '<div class="wrap uzb-wrap">';
		echo '<div class="uzb-head"><div class="uzb-logo"><span class="dashicons dashicons-megaphone"></span></div><div><div class="uzb-title">MAAZAP</div><div class="uzb-sub">Notícias do site nos seus grupos de WhatsApp</div></div></div>';
		echo '<h2 class="nav-tab-wrapper" style="margin-top:8px;">';
		foreach ( $tabs as $k => $label ) {
			printf( '<a href="?page=uzapi-gn&tab=%s" class="nav-tab %s">%s</a>', $k, $tab === $k ? 'nav-tab-active' : '', esc_html( $label ) );
		}
		echo '</h2>';
		$this->{ 'aba_' . $tab }();
		echo '</div>';
	}

	private function css() {
		return '<style>
		.uzb-wrap{max-width:1080px}
		.uzb-head{display:flex;align-items:center;gap:12px;margin:10px 0 2px}
		.uzb-logo{width:42px;height:42px;border-radius:11px;background:#25D366;color:#fff;display:flex;align-items:center;justify-content:center}
		.uzb-logo .dashicons{font-size:24px;width:24px;height:24px}
		.uzb-title{font-size:18px;font-weight:600;color:#1d2327}
		.uzb-sub{font-size:13px;color:#646970}
		.uzb-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px;margin:18px 0}
		.uzb-kpi{background:#fff;border:1px solid #e2e4e7;border-radius:12px;padding:16px 18px}
		.uzb-kpi .lbl{font-size:13px;color:#646970;display:flex;align-items:center;gap:6px}
		.uzb-kpi .lbl .dashicons{font-size:17px;width:17px;height:17px}
		.uzb-kpi .val{font-size:28px;font-weight:600;color:#1d2327;margin-top:6px;line-height:1.1}
		.uzb-kpi .dl{font-size:12px;margin-top:6px}
		.uzb-up{color:#008a20}.uzb-mut{color:#787c82}
		.uzb-card{background:#fff;border:1px solid #e2e4e7;border-radius:12px;padding:16px 18px;margin:14px 0}
		.uzb-card h3{margin:0 0 12px;font-size:14px;color:#1d2327;display:flex;align-items:center;gap:6px}
		.uzb-card h3 .dashicons{font-size:18px;width:18px;height:18px;color:#646970}
		.uzb-two{display:grid;grid-template-columns:1fr 1fr;gap:14px}
		@media(max-width:782px){.uzb-two{grid-template-columns:1fr}}
		.uzb-list{margin:0}
		.uzb-li{display:flex;justify-content:space-between;align-items:center;padding:9px 0;border-bottom:1px solid #f0f0f1;font-size:13px}
		.uzb-li:last-child{border-bottom:0}
		.uzb-li b{font-weight:600;color:#1d2327}
		.uzb-pill{display:inline-flex;align-items:center;gap:5px;font-size:12px;padding:4px 10px;border-radius:999px}
		.uzb-pill-ok{background:#edfaef;color:#008a20}
		.uzb-pill-off{background:#f0f0f1;color:#787c82}
		.uzb-empty{color:#646970;font-size:13px;padding:8px 0}
		.uzb-mini{font-size:12px;color:#787c82}
		</style>';
	}

	/* -------- DASHBOARD -------- */
	private function aba_dashboard() {
		$this->snapshot();

		$stats   = get_option( self::OPT_STATS, array() );
		$total   = (int) ( $stats['total'] ?? 0 );
		$sucesso = (int) ( $stats['sucesso'] ?? 0 );
		$falha   = (int) ( $stats['falha'] ?? 0 );
		$taxa    = $total ? round( $sucesso / $total * 100, 1 ) : null;

		$pd30    = $this->envios_por_dia( 30 );
		$env30   = array_sum( $pd30 );
		$hoje    = current_time( 'Y-m-d' );
		$envHoje = (int) ( ( $stats['por_dia'][ $hoje ] ?? 0 ) );

		$mapa    = $this->grupos_map();
		$nGrupos = count( $mapa );
		$membros = $this->total_membros();

		if ( ! $this->configurado() ) {
			echo '<div class="uzb-card"><h3><span class="dashicons dashicons-info"></span>Comece por aqui</h3><p class="uzb-empty">Configure suas credenciais da UzAPI em <a href="?page=uzapi-gn&tab=config">Configurações</a> e clique em “Sincronizar grupos”. As métricas aparecem assim que os primeiros envios acontecerem.</p></div>';
		}

		$fmt = function ( $v ) {
			return null === $v ? '—' : number_format_i18n( $v );
		};
		?>
		<div class="uzb-kpis">
			<div class="uzb-kpi">
				<div class="lbl"><span class="dashicons dashicons-email-alt"></span>Envios (30 dias)</div>
				<div class="val"><?php echo esc_html( number_format_i18n( $env30 ) ); ?></div>
				<div class="dl uzb-mut"><?php echo esc_html( $envHoje ); ?> hoje · <?php echo esc_html( number_format_i18n( $total ) ); ?> no total</div>
			</div>
			<div class="uzb-kpi">
				<div class="lbl"><span class="dashicons dashicons-groups"></span>Grupos</div>
				<div class="val"><?php echo esc_html( number_format_i18n( $nGrupos ) ); ?></div>
				<div class="dl uzb-mut">alcance de transmissão</div>
			</div>
			<div class="uzb-kpi">
				<div class="lbl"><span class="dashicons dashicons-admin-users"></span>Membros</div>
				<div class="val"><?php echo esc_html( $fmt( $membros ) ); ?></div>
				<div class="dl uzb-mut"><?php echo null === $membros ? 'sincronize os grupos' : 'somando todos os grupos'; ?></div>
			</div>
			<div class="uzb-kpi">
				<div class="lbl"><span class="dashicons dashicons-yes-alt"></span>Taxa de sucesso</div>
				<div class="val"><?php echo null === $taxa ? '—' : esc_html( number_format_i18n( $taxa, 1 ) ) . '%'; ?></div>
				<div class="dl uzb-mut"><?php echo esc_html( number_format_i18n( $sucesso ) ); ?> ok · <?php echo esc_html( number_format_i18n( $falha ) ); ?> falhas</div>
			</div>
		</div>

		<div class="uzb-card">
			<h3><span class="dashicons dashicons-chart-line"></span><?php echo null === $membros ? 'Crescimento de grupos' : 'Crescimento de membros'; ?></h3>
			<?php echo $this->grafico_crescimento(); ?>
		</div>

		<div class="uzb-two">
			<div class="uzb-card">
				<h3><span class="dashicons dashicons-chart-bar"></span>Envios por dia · 14 dias</h3>
				<?php echo $this->svg_bars( $this->envios_por_dia( 14 ) ); ?>
			</div>
			<div class="uzb-card">
				<h3><span class="dashicons dashicons-star-filled"></span>Top grupos por membros</h3>
				<?php $this->lista_top_grupos( $mapa ); ?>
			</div>
		</div>

		<div class="uzb-card">
			<h3><span class="dashicons dashicons-backup"></span>Últimos disparos</h3>
			<?php $this->lista_ultimos_disparos(); ?>
		</div>
		<?php
	}

	private function grafico_crescimento() {
		$snaps = get_option( self::OPT_SNAP, array() );
		if ( ! is_array( $snaps ) || count( $snaps ) < 2 ) {
			return '<p class="uzb-empty">Coletando dados… o gráfico de crescimento aparece após alguns dias de histórico (um ponto por dia).</p>';
		}
		$temMembros = false;
		foreach ( $snaps as $s ) {
			if ( isset( $s['membros'] ) && null !== $s['membros'] ) {
				$temMembros = true;
				break;
			}
		}
		$serie = array();
		foreach ( $snaps as $s ) {
			$serie[] = $temMembros ? (int) ( $s['membros'] ?? 0 ) : (int) ( $s['grupos'] ?? 0 );
		}
		$atual = end( $serie );
		return '<p class="uzb-mini" style="margin:0 0 6px">Atual: <b>' . esc_html( number_format_i18n( $atual ) ) . '</b> · ' . count( $serie ) . ' dias de histórico</p>' . $this->svg_area( $serie );
	}

	private function lista_top_grupos( $mapa ) {
		$com = array_filter( $mapa, function ( $g ) {
			return null !== $g['membros'];
		} );
		if ( empty( $com ) ) {
			echo '<p class="uzb-empty">Sem contagem de membros ainda. Vá em Configurações → “Sincronizar grupos”.</p>';
			return;
		}
		uasort( $com, function ( $a, $b ) {
			return (int) $b['membros'] - (int) $a['membros'];
		} );
		echo '<div class="uzb-list">';
		$i = 0;
		foreach ( $com as $g ) {
			if ( $i++ >= 6 ) {
				break;
			}
			echo '<div class="uzb-li"><span>' . esc_html( $g['nome'] ) . '</span><b>' . esc_html( number_format_i18n( $g['membros'] ) ) . '</b></div>';
		}
		echo '</div>';
	}

	private function lista_ultimos_disparos() {
		$logs = get_option( self::OPT_LOG, array() );
		if ( empty( $logs ) ) {
			echo '<p class="uzb-empty">Nenhum disparo ainda. Faça um <a href="?page=uzapi-gn&tab=manual">envio manual</a> ou publique uma notícia.</p>';
			return;
		}
		echo '<div class="uzb-list">';
		foreach ( array_slice( $logs, 0, 6 ) as $l ) {
			echo '<div class="uzb-li"><span><b>' . esc_html( $l['tipo'] ) . '</b> · ' . esc_html( $l['resumo'] ) . '</span><span class="uzb-mini">' . esc_html( $l['quando'] ) . '</span></div>';
		}
		echo '</div>';
	}

	/* -------- GRÁFICOS SVG (sem dependência externa) -------- */

	private function svg_area( $valores, $cor = '#2271b1' ) {
		$valores = array_values( array_map( 'floatval', $valores ) );
		$n       = count( $valores );
		if ( $n < 2 ) {
			return '<p class="uzb-empty">Coletando dados…</p>';
		}
		$w = 600; $h = 150; $pad = 10;
		$min = min( $valores ); $max = max( $valores );
		$range = ( $max - $min ) ?: 1;
		$stepX = ( $w - 2 * $pad ) / ( $n - 1 );
		$pts = array();
		foreach ( $valores as $i => $v ) {
			$x     = $pad + $i * $stepX;
			$y     = $h - $pad - ( ( $v - $min ) / $range ) * ( $h - 2 * $pad );
			$pts[] = array( round( $x, 1 ), round( $y, 1 ) );
		}
		$line = '';
		foreach ( $pts as $i => $p ) {
			$line .= ( $i ? ' L' : 'M' ) . $p[0] . ' ' . $p[1];
		}
		$area = $line . ' L' . $pts[ $n - 1 ][0] . ' ' . ( $h - $pad ) . ' L' . $pts[0][0] . ' ' . ( $h - $pad ) . ' Z';
		$last = $pts[ $n - 1 ];
		$svg  = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" style="width:100%;height:auto;display:block" role="img" aria-label="Gráfico de crescimento">';
		$svg .= '<path d="' . esc_attr( $area ) . '" fill="rgba(34,113,177,0.12)" stroke="none"/>';
		$svg .= '<path d="' . esc_attr( $line ) . '" fill="none" stroke="' . esc_attr( $cor ) . '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>';
		$svg .= '<circle cx="' . $last[0] . '" cy="' . $last[1] . '" r="4" fill="' . esc_attr( $cor ) . '"/>';
		$svg .= '</svg>';
		return $svg;
	}

	private function svg_bars( $mapa, $cor = '#2271b1' ) {
		$vals = array_values( $mapa );
		$n    = count( $vals );
		if ( ! $n || 0 === array_sum( $vals ) ) {
			return '<p class="uzb-empty">Sem envios registrados nos últimos dias.</p>';
		}
		$w = 600; $h = 150; $pad = 10; $lblH = 14;
		$max  = max( $vals ) ?: 1;
		$slot = ( $w - 2 * $pad ) / $n;
		$bw   = $slot * 0.62;
		$keys = array_keys( $mapa );
		$svg  = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" style="width:100%;height:auto;display:block" role="img" aria-label="Envios por dia">';
		foreach ( $vals as $i => $v ) {
			$bh = ( $v / $max ) * ( $h - 2 * $pad - $lblH );
			$x  = $pad + $i * $slot + ( $slot - $bw ) / 2;
			$y  = $h - $pad - $lblH - $bh;
			$svg .= '<rect x="' . round( $x, 1 ) . '" y="' . round( $y, 1 ) . '" width="' . round( $bw, 1 ) . '" height="' . round( max( $bh, 1 ), 1 ) . '" rx="3" fill="' . esc_attr( $cor ) . '"/>';
			$dia  = substr( $keys[ $i ], 8, 2 );
			$svg .= '<text x="' . round( $x + $bw / 2, 1 ) . '" y="' . ( $h - 3 ) . '" font-size="9" text-anchor="middle" fill="#8c8f94">' . esc_html( $dia ) . '</text>';
		}
		$svg .= '</svg>';
		return $svg;
	}

	/* -------- CONFIG -------- */
	private function aba_config() {
		$c = wp_parse_args( $this->cfg(), array(
			'ativo' => 0, 'username' => '', 'version' => 'v1', 'phone_number_id' => '', 'token' => '',
			'formato' => 'texto', 'delay' => 5, 'jitter' => 3, 'delay_min' => 3, 'mencionar_todos' => 0,
			'crescimento' => 0, 'crescimento_texto' => '📩 Convide amigos para o grupo:',
			'template' => "📰 *{titulo}*\n\n{link}", 'post_types' => array( 'post' ),
		) );
		$grupos = $this->grupos_map();
		$O      = self::OPT;

		if ( isset( $_GET['conn'] ) ) {
			$cr = (string) get_transient( 'uzapi_gn_conn' );
			$ok = ( false !== strpos( $cr, 'HTTP 200' ) || false !== strpos( $cr, 'HTTP 201' ) );
			echo '<div class="notice notice-' . ( $ok ? 'success' : 'error' ) . '"><p><strong>' . ( $ok ? 'Conexão funcionando.' : 'Não foi possível conectar.' ) . '</strong></p>';
			if ( ! $ok ) {
				echo '<p>Confira se o usuário, o ID da instância e o token são da <strong>mesma instância</strong> e se o número está conectado no painel da UzAPI.</p>';
				echo '<p class="uzb-mini">' . esc_html( wp_trim_words( $cr, 40, '…' ) ) . '</p>';
			}
			echo '</div>';
		}
		if ( isset( $_GET['sincronizado'] ) ) {
			$sm = (int) get_transient( 'uzapi_gn_sync_membros' );
			$sc = (int) get_transient( 'uzapi_gn_sync_count' );
			if ( $sc > 0 ) {
				echo '<div class="notice notice-success is-dismissible"><p>Pronto! <strong>' . $sc . '</strong> grupo(s) sincronizado(s), com contagem de membros em <strong>' . $sm . '</strong> deles.</p></div>';
			} else {
				echo '<div class="notice notice-error"><p>Não foi possível listar seus grupos.</p><p class="uzb-mini">' . esc_html( wp_trim_words( (string) get_transient( 'uzapi_gn_sync_raw' ), 40, '…' ) ) . '</p></div>';
			}
		}
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'uzapi_gn' ); ?>
			<div class="uzb-card">
				<h3><span class="dashicons dashicons-admin-network"></span>Conexão com o WhatsApp</h3>
				<p class="description" style="margin:0 0 12px;">Estes dados vêm do seu painel na UzAPI, onde você conecta o número de WhatsApp lendo o QR Code. Copie e cole cada campo abaixo. Use um número dedicado ao site, não o seu pessoal.</p>
				<table class="form-table">
					<tr><th>Seu usuário</th><td>
						<input type="text" class="regular-text" name="<?php echo $O; ?>[username]" value="<?php echo esc_attr( $c['username'] ); ?>">
						<p class="description">O nome de usuário da sua conta na UzAPI.</p>
					</td></tr>
					<tr><th>Versão da API</th><td>
						<input type="text" class="small-text" name="<?php echo $O; ?>[version]" value="<?php echo esc_attr( $c['version'] ); ?>">
						<p class="description">Deixe como <code>v1</code>, a não ser que a UzAPI oriente outro valor.</p>
					</td></tr>
					<tr><th>ID da instância</th><td>
						<input type="text" class="regular-text" name="<?php echo $O; ?>[phone_number_id]" value="<?php echo esc_attr( $c['phone_number_id'] ); ?>">
						<p class="description">Identificador do número conectado, chamado de <code>phone_number_id</code> no painel da UzAPI.</p>
					</td></tr>
					<tr><th>Token de acesso</th><td>
						<input type="password" class="regular-text" name="<?php echo $O; ?>[token]" value="<?php echo esc_attr( $c['token'] ); ?>" autocomplete="new-password" spellcheck="false">
						<p class="description">A senha de acesso à API. Use o token <strong>da mesma instância</strong> informada acima — token de outra instância faz o envio falhar. Depois de colar, clique em <strong>Salvar alterações</strong>.</p>
					</td></tr>
				</table>
			</div>
			<div class="uzb-card">
				<h3><span class="dashicons dashicons-controls-play"></span>Envio automático ao publicar</h3>
				<p class="description" style="margin:0 0 12px;">Com isto ligado, toda notícia publicada é enviada sozinha para os seus grupos. Cada notícia é enviada uma única vez — editar depois não gera reenvio.</p>
				<table class="form-table">
					<tr><th>Ligar envio automático</th><td>
						<label><input type="checkbox" name="<?php echo $O; ?>[ativo]" value="1" <?php checked( $c['ativo'], 1 ); ?>> Enviar para os grupos assim que eu publicar</label>
						<p class="description">Deixe desligado enquanto estiver testando. Você ainda pode enviar quando quiser pela aba <strong>Envio manual</strong>.</p>
					</td></tr>
					<tr><th>Como a mensagem aparece</th><td>
						<select name="<?php echo $O; ?>[formato]">
							<option value="texto" <?php selected( $c['formato'], 'texto' ); ?>>Texto com prévia do link</option>
							<option value="imagem" <?php selected( $c['formato'], 'imagem' ); ?>>Foto da notícia com legenda</option>
						</select>
						<p class="description"><strong>Texto com prévia:</strong> envia a mensagem e o WhatsApp monta um cartãozinho com a imagem do link. <strong>Foto com legenda:</strong> envia a imagem destacada da notícia como foto, com o texto embaixo — aparece maior e nunca falha. Se a prévia não estiver aparecendo nos seus grupos, use a segunda opção.</p>
					</td></tr>
					<tr><th>Modelo da mensagem</th><td>
						<textarea name="<?php echo $O; ?>[template]" rows="5" class="large-text"><?php echo esc_textarea( $c['template'] ); ?></textarea>
						<p class="description">Escreva como a mensagem deve ficar. Onde você colocar as etiquetas abaixo, o plugin troca pelos dados da notícia:<br>
						<code>{titulo}</code> título · <code>{link}</code> endereço · <code>{resumo}</code> primeiras linhas · <code>{categoria}</code> · <code>{autor}</code> · <code>{data}</code> · <code>{site}</code> nome do site<br>
						Para destacar, use <code>*negrito*</code> e <code>_itálico_</code>, como no próprio WhatsApp.</p>
					</td></tr>
					<tr><th>O que será enviado</th><td>
						<?php foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $pt ) : ?>
							<label style="margin-right:12px;"><input type="checkbox" name="<?php echo $O; ?>[post_types][]" value="<?php echo esc_attr( $pt->name ); ?>" <?php checked( in_array( $pt->name, (array) $c['post_types'], true ) ); ?>> <?php echo esc_html( $pt->labels->singular_name ); ?></label>
						<p class="description">Marque os tipos de conteúdo que devem ir para os grupos. Normalmente apenas “Post”.</p>
						<?php endforeach; ?>
					</td></tr>
					<tr><th>Intervalo entre grupos</th><td>
						<input type="number" min="0" class="small-text" name="<?php echo $O; ?>[delay]" value="<?php echo esc_attr( $c['delay'] ); ?>"> segundos, variando até <input type="number" min="0" class="small-text" name="<?php echo $O; ?>[jitter]" value="<?php echo esc_attr( $c['jitter'] ); ?>"> s a mais
						<p class="description">Espaça o envio entre um grupo e outro, com uma variação aleatória para parecer natural. Disparar tudo de uma vez é o que mais chama atenção do WhatsApp e pode bloquear seu número. Recomendado: 5 e 3.</p>
					</td></tr>
					<tr><th>Pausa antes de cada envio</th><td>
						<input type="number" min="0" class="small-text" name="<?php echo $O; ?>[delay_min]" value="<?php echo esc_attr( $c['delay_min'] ); ?>"> segundos
						<p class="description">Uma pausa mínima aplicada a toda mensagem. Ajuda o WhatsApp a montar a prévia do link antes de enviar. Recomendado: 3 a 5.</p>
					</td></tr>
					<tr><th>Marcar todos do grupo</th><td>
						<label><input type="checkbox" name="<?php echo $O; ?>[mencionar_todos]" value="1" <?php checked( $c['mencionar_todos'], 1 ); ?>> Notificar todos os participantes (@todos)</label>
						<p class="description">⚠️ Faz o celular de todo mundo apitar a cada notícia. Incomoda os membros, provoca saídas do grupo e aumenta muito o risco de bloqueio. Use só em casos excepcionais.</p>
					</td></tr>
					<tr><th>Convite para crescer o grupo</th><td>
						<label><input type="checkbox" name="<?php echo $O; ?>[crescimento]" value="1" <?php checked( $c['crescimento'], 1 ); ?>> Incluir o link de convite do grupo na mensagem</label>
						<p class="description">Cada grupo recebe o convite dele mesmo, para os membros compartilharem e trazerem gente nova. Os convites são buscados quando você clica em <strong>Sincronizar grupos</strong>, e só funcionam nos grupos em que o seu número é administrador.</p>
						<input type="text" class="large-text" name="<?php echo $O; ?>[crescimento_texto]" value="<?php echo esc_attr( $c['crescimento_texto'] ); ?>" style="margin-top:6px;">
						<p class="description">Frase que aparece antes do convite.</p>
					</td></tr>
				</table>
			</div>
			<?php submit_button(); ?>
		</form>

		<div class="uzb-card">
			<h3><span class="dashicons dashicons-admin-tools"></span>Seus grupos</h3>
			<p class="description" style="margin-bottom:10px;"><strong>Testar conexão</strong> confirma que o WhatsApp está conectado. <strong>Sincronizar grupos</strong> busca a lista de grupos, quantos membros cada um tem e os links de convite — faça isso sempre que entrar ou sair de um grupo. Lembre de <strong>salvar</strong> as configurações antes de usar os botões.</p>
			<p style="display:flex;gap:10px;flex-wrap:wrap;">
				<?php $this->form_botao( 'uzapi_gn_testconn', 'Testar conexão' ); ?>
				<?php $this->form_botao( 'uzapi_gn_sync', 'Sincronizar grupos' ); ?>
			</p>
			<h4 style="margin:.5em 0;">Grupos encontrados (<?php echo count( $grupos ); ?>)</h4>
			<?php if ( $grupos ) : ?>
				<ul style="max-height:220px;overflow:auto;background:#f6f7f7;padding:10px 24px;border-radius:8px;">
					<?php foreach ( $grupos as $id => $g ) : ?>
						<li><strong><?php echo esc_html( $g['nome'] ); ?></strong> <?php echo null !== $g['membros'] ? '<span class="uzb-mini">(' . esc_html( number_format_i18n( $g['membros'] ) ) . ' membros)</span>' : ''; ?> <?php echo ! empty( $g['link'] ) ? '<span class="uzb-mini" title="link de convite capturado">🔗</span>' : ''; ?> <code style="color:#888;"><?php echo esc_html( $id ); ?></code></li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p class="uzb-empty">Nenhum grupo sincronizado ainda.</p>
			<?php endif; ?>
		</div>
		<?php
	}

	private function form_botao( $action, $label ) {
		printf(
			'<form method="post" action="%s" style="display:inline;"><input type="hidden" name="action" value="%s">%s%s</form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_attr( $action ),
			wp_nonce_field( $action, '_wpnonce', true, false ),
			get_submit_button( $label, 'secondary', 'submit', false )
		);
	}

	/* -------- ENVIO MANUAL -------- */
	private function aba_manual() {
		$grupos = $this->grupos_map();
		$posts  = get_posts( array( 'numberposts' => 30, 'post_status' => 'publish' ) );

		if ( isset( $_GET['enviado'] ) ) {
			echo '<div class="notice notice-success"><p>Enviado para <strong>' . (int) get_transient( 'uzapi_gn_manual_count' ) . '</strong> grupo(s). Veja o <a href="?page=uzapi-gn&tab=dashboard">Dashboard</a>.</p></div>';
		}
		if ( isset( $_GET['erro'] ) ) {
			$m = array(
				'grupos' => 'Selecione ao menos um grupo.',
				'msg'    => 'Escreva a mensagem ou selecione uma notícia.',
				'imagem' => 'Informe a URL da imagem (ou selecione uma notícia com imagem destaque).',
				'poll'   => 'Enquete precisa de pergunta e ao menos 2 opções.',
				'config' => 'Configure a API primeiro.',
			);
			echo '<div class="notice notice-error"><p>' . esc_html( $m[ $_GET['erro'] ] ?? 'Erro.' ) . '</p></div>';
		}
		if ( empty( $grupos ) ) {
			echo '<div class="uzb-card"><p class="uzb-empty">Nenhum grupo. Vá em <a href="?page=uzapi-gn&tab=config">Configurações</a> → “Sincronizar grupos”.</p></div>';
			return;
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="uzapi_gn_manual">
			<?php wp_nonce_field( 'uzapi_gn_manual' ); ?>

			<div class="uzb-card">
				<h3><span class="dashicons dashicons-format-chat"></span>1. O que você quer enviar</h3>
				<select name="tipo" id="uzapi-tipo">
					<option value="texto">Texto com prévia do link</option>
					<option value="imagem">Foto com legenda</option>
					<option value="poll">Enquete</option>
				</select>
				<p class="description" style="margin-top:8px;">A enquete só funciona em grupos e aparece como votação dentro do WhatsApp.</p>
			</div>

			<div class="uzb-card">
				<h3><span class="dashicons dashicons-edit"></span>2. Monte a mensagem</h3>
				<table class="form-table">
					<tr class="uzapi-l-texto uzapi-l-post"><th>Usar uma notícia</th><td>
						<select name="post_id" style="min-width:420px;">
							<option value="0">— vou escrever a mensagem eu mesmo —</option>
							<?php foreach ( $posts as $p ) : ?><option value="<?php echo (int) $p->ID; ?>"><?php echo esc_html( get_the_title( $p ) ); ?></option><?php endforeach; ?>
						</select>
						<p class="description">Escolha uma notícia e o plugin preenche sozinho o texto (usando o seu modelo) e a foto destacada. Você ainda pode alterar tudo nos campos abaixo.</p>
					</td></tr>
					<tr class="uzapi-l-texto"><th>Mensagem</th><td>
						<textarea name="mensagem" rows="4" class="large-text" placeholder="Escreva aqui, ou deixe em branco para usar o texto da notícia escolhida acima..."></textarea>
						<p class="description">Use <code>*negrito*</code> e <code>_itálico_</code> como no WhatsApp. Se estiver enviando uma foto, este texto vira a legenda dela.</p>
					</td></tr>
					<tr class="uzapi-l-imagem" style="display:none;"><th>Endereço da foto</th><td>
						<input type="url" name="imagem" class="regular-text" placeholder="https://...">
						<p class="description">Deixe em branco se escolheu uma notícia acima — a foto destacada dela será usada automaticamente.</p>
					</td></tr>
					<tr class="uzapi-l-poll" style="display:none;"><th>Pergunta</th><td><input type="text" name="pergunta" class="large-text" placeholder="Ex.: Qual assunto você quer ver amanhã?"></td></tr>
					<tr class="uzapi-l-poll" style="display:none;"><th>Opções de resposta</th><td><textarea name="opcoes" rows="4" class="large-text" placeholder="Economia&#10;Política&#10;Esportes"></textarea><p class="description">Uma opção por linha, no mínimo duas.</p></td></tr>
					<tr class="uzapi-l-poll" style="display:none;"><th>Quantas podem ser marcadas</th><td><input type="number" name="max" min="1" value="1" class="small-text"><p class="description">Use 1 para escolha única.</p></td></tr>
				</table>
				<p class="uzapi-l-texto"><label><input type="checkbox" name="mencionar_todos" value="1"> Notificar todos os participantes (@todos) — ⚠️ use com moderação</label></p>
				<p class="uzapi-l-texto"><label><input type="checkbox" name="crescimento" value="1" <?php checked( $this->cfg( 'crescimento', 0 ), 1 ); ?>> Incluir o link de convite do grupo, para os membros compartilharem</label></p>
			</div>

			<div class="uzb-card">
				<h3><span class="dashicons dashicons-groups"></span>3. Escolha os grupos</h3>
				<p><label><input type="checkbox" id="uzapi-todos"> <strong>Marcar todos</strong></label> &nbsp;|&nbsp; <?php echo count( $grupos ); ?> grupo(s) · o número ao lado de cada um é a quantidade de membros</p>
				<ul style="max-height:320px;overflow:auto;background:#f6f7f7;padding:10px 20px;border:1px solid #dcdcde;border-radius:8px;">
					<?php foreach ( $grupos as $id => $g ) : ?>
						<li style="margin:4px 0;"><label><input type="checkbox" class="uzapi-grupo" name="grupos[]" value="<?php echo esc_attr( $id ); ?>"> <strong><?php echo esc_html( $g['nome'] ); ?></strong> <?php echo null !== $g['membros'] ? '<span class="uzb-mini">(' . esc_html( number_format_i18n( $g['membros'] ) ) . ')</span>' : ''; ?></label></li>
					<?php endforeach; ?>
				</ul>
				<?php submit_button( 'Enviar agora', 'primary', 'submit', false ); ?>
				<p class="description">💡 Marque <strong>só 1 grupo</strong> para testar como a mensagem aparece antes de disparar pra todos.</p>
			</div>
		</form>
		<script>
			(function(){
				var t=document.getElementById('uzapi-todos'), itens=document.querySelectorAll('.uzapi-grupo');
				if(t){ t.addEventListener('change',function(){ itens.forEach(function(c){ c.checked=t.checked; }); }); }
				var tipo=document.getElementById('uzapi-tipo');
				function mostra(sel,on){ document.querySelectorAll(sel).forEach(function(e){ e.style.display=on?'':'none'; }); }
				function upd(){ var v=tipo.value; mostra('.uzapi-l-imagem',v==='imagem'); mostra('.uzapi-l-poll',v==='poll'); mostra('.uzapi-l-texto',v!=='poll'); mostra('.uzapi-l-post',v!=='poll'); }
				tipo.addEventListener('change',upd); upd();
			})();
		</script>
		<?php
	}

	/* -------- TESTE -------- */
	private function aba_teste() {
		if ( isset( $_GET['enviado'] ) ) {
			echo '<div class="notice notice-success"><p>Enviado. Resposta da API:</p><pre style="background:#f6f7f7;padding:10px;">' . esc_html( get_transient( 'uzapi_gn_teste_res' ) ) . '</pre></div>';
		}
		if ( isset( $_GET['erro'] ) ) {
			$m = array( 'campos' => 'Preencha número e mensagem.', 'config' => 'Configure a API primeiro.' );
			echo '<div class="notice notice-error"><p>' . esc_html( $m[ $_GET['erro'] ] ?? 'Erro.' ) . '</p></div>';
		}
		?>
		<div class="uzb-card">
			<h3><span class="dashicons dashicons-smartphone"></span>Enviar um teste para o seu WhatsApp</h3>
			<p class="description" style="margin:0 0 12px;">Antes de disparar para os grupos, mande a mensagem para o seu próprio número e veja exatamente como ela vai chegar. O envio usa as mesmas configurações do envio real.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="uzapi_gn_teste">
				<?php wp_nonce_field( 'uzapi_gn_teste' ); ?>
				<table class="form-table">
					<tr><th>Número de WhatsApp</th><td>
						<input type="text" class="regular-text" name="numero" placeholder="5521999998888">
						<p class="description">Digite com código do país e DDD, apenas números: 55 + DDD + número. Exemplo: <code>5521999998888</code></p>
					</td></tr>
					<tr><th>Mensagem</th><td>
						<textarea name="mensagem" rows="4" class="large-text" placeholder="Escreva a mensagem de teste..."></textarea>
						<p class="description">Se colar o endereço de uma notícia, dá para conferir se a prévia do link está aparecendo.</p>
					</td></tr>
					<tr><th>Foto (opcional)</th><td>
						<input type="url" class="regular-text" name="imagem" placeholder="https://...">
						<p class="description">Preencha para testar o envio como foto com legenda.</p>
					</td></tr>
				</table>
				<?php submit_button( 'Enviar teste', 'primary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/* -------- LOGS -------- */
	private function aba_logs() {
		$logs = get_option( self::OPT_LOG, array() );
		echo '<div class="uzb-card"><h3><span class="dashicons dashicons-list-view"></span>Histórico de envios</h3>';
		echo '<p class="description">Cada envio realizado, com o resultado grupo a grupo. Clique em uma linha para abrir os detalhes — útil para descobrir se alguma mensagem falhou.</p>';
		if ( empty( $logs ) ) {
			echo '<p class="uzb-empty">Nenhum envio ainda. Publique uma notícia ou use a aba Envio manual.</p></div>';
			return;
		}
		$prox = wp_next_scheduled( self::CRON_SNAP );
		echo '<p class="uzb-mini">Próxima atualização automática dos números de grupos e membros: ' . ( $prox ? esc_html( wp_date( 'd/m \à\s H:i', $prox ) ) : 'não agendada' ) . '</p>';
		foreach ( $logs as $l ) {
			echo '<details style="margin:6px 0;background:#f6f7f7;padding:8px 12px;border:1px solid #dcdcde;border-radius:8px;">';
			echo '<summary><strong>' . esc_html( $l['tipo'] ) . '</strong> — ' . esc_html( $l['resumo'] ) . ' <span class="uzb-mini">(' . esc_html( $l['quando'] ) . ')</span></summary>';
			if ( ! empty( $l['linhas'] ) ) {
				echo '<pre style="max-height:220px;overflow:auto;margin-top:8px;">' . esc_html( implode( "\n", $l['linhas'] ) ) . '</pre>';
			}
			echo '</details>';
		}
		echo '</div>';
	}
}

register_activation_hook( __FILE__, array( 'UzAPI_Broadcaster', 'ativar' ) );
register_deactivation_hook( __FILE__, array( 'UzAPI_Broadcaster', 'desativar' ) );

new UzAPI_Broadcaster();
