=== MAAZAP ===
Contributors: jeffersonornellas
Tags: whatsapp, grupos, notícias, automação, disparo
Requires at least: 5.6
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 3.12.5
License: GPLv2 or later

Publique uma notícia no seu site e ela vai sozinha para todos os seus grupos de WhatsApp.

== Description ==

O MAAZAP conecta o seu site WordPress aos seus grupos de WhatsApp. Assim que você publica uma notícia, ela é enviada automaticamente para todos os grupos, do jeito que você configurou.

**O que ele faz**

* Envia sozinho toda notícia publicada, uma única vez por notícia
* Três formatos: texto com prévia do link, foto da notícia com legenda, ou enquete
* Modelo de mensagem personalizável, com etiquetas que viram título, link, resumo, categoria, autor e data
* Painel com número de grupos, total de membros, envios realizados, taxa de sucesso e gráfico de crescimento
* Envio manual quando você quiser, escolhendo os grupos um a um
* Teste no seu próprio número antes de disparar para todo mundo
* Convite do grupo anexado à mensagem, para os membros trazerem gente nova
* Histórico completo de envios, grupo a grupo

**Cuidados com o seu número**

O plugin espaça os envios entre um grupo e outro, com variação aleatória, para reduzir o risco de bloqueio pelo WhatsApp. Use sempre um número dedicado ao site, nunca o seu pessoal.

**O que você precisa**

Uma conta na UzAPI (uzapi.com.br) com um número de WhatsApp conectado. É de lá que saem os dados de conexão pedidos na tela de configurações.

== Installation ==

1. Envie a pasta `maazap` para `wp-content/plugins/` do seu site, ou instale o arquivo .zip pelo painel em Plugins > Adicionar novo > Enviar plugin
2. Ative o plugin
3. Vá em Configurações > MAAZAP e preencha os dados de conexão da UzAPI
4. Clique em Salvar alterações
5. Clique em Testar conexão e depois em Sincronizar grupos
6. Faça um teste pelo seu número na aba Teste
7. Quando estiver tudo certo, ligue o envio automático

== Frequently Asked Questions ==

= A prévia do link não aparece nos grupos =

Aumente a "Pausa antes de cada envio" para 5 segundos. Se ainda assim não aparecer, verifique se o seu site não está bloqueando leitores automáticos: alguns plugins de segurança respondem com erro para quem não é navegador, e isso impede o WhatsApp de ler a imagem da notícia. Como alternativa garantida, mude o formato para "Foto com legenda".

= A foto não chega nos grupos =

Confirme que a notícia tem imagem destacada. Se tiver e mesmo assim não chegar, provavelmente o seu plugin de segurança está bloqueando o acesso à pasta de uploads.

= Posso usar em mais de um site? =

Sim, mas cada site precisa da sua própria instância na UzAPI, com um número de WhatsApp próprio. Dois sites na mesma instância disputam a mesma conexão.

= Como faço para voltar a uma versão anterior? =

Todas as versões ficam disponíveis na página de releases do projeto no GitHub. Baixe o .zip da versão desejada e instale pelo painel do WordPress.

== Changelog ==

= 3.12.5 =
* Campo "Versao da API" ja vem preenchido com o valor recomendado pela UzAPI

= 3.12.4 =
* Correcao: o aviso de atualizacao podia demorar ate 6 horas para aparecer
* "Verificar novamente" e reativar o plugin agora buscam a versao na hora

= 3.12.3 =
* Previa do link volta a ser sempre enviada

= 3.12.2 =
* Correcao: envio para os grupos podia falhar quando a previa do link estava ativa
* A previa do link virou uma opcao, desligada por padrao

= 3.12.1 =
* Atalho "Configurações" direto na lista de plugins

= 3.12.0 =
* Textos de toda a interface reescritos em linguagem simples
* Atualização automática pelo painel do WordPress
* Ferramentas de diagnóstico técnico removidas da tela

= 3.11.0 =
* Prévia do link volta a aparecer nas mensagens de texto

= 3.10.0 =
* Avisos claros quando as credenciais não foram salvas
* Correção do preenchimento automático indevido no campo do token

= 3.9.0 =
* Correção importante: a foto da notícia agora é enviada corretamente, mesmo em sites com proteção contra acessos automáticos

= 3.8.0 =
* Pausa mínima aplicada a todas as mensagens

= 3.7.0 =
* Todo link do site passa a levar identificação de origem, permitindo medir as visitas vindas dos grupos

= 3.6.0 =
* Convites de grupo passam a ser capturados sempre que você sincroniza

= 3.5.0 =
* Reconhecimento de links colados manualmente na mensagem

= 3.2.0 =
* Convite do próprio grupo anexado à mensagem, para atrair novos membros

= 3.0.0 =
* Novo painel com métricas de grupos, membros, envios e crescimento
* Visual completamente renovado

= 2.0.0 =
* Envio manual com escolha de grupos
* Novos formatos: foto com legenda e enquete
* Teste para número próprio

= 1.0.0 =
* Primeira versão: envio automático das notícias publicadas para os grupos
