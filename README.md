# MAAZAP

Publique uma notícia no seu site WordPress e ela vai sozinha para todos os seus grupos de WhatsApp.

Feito por [Jefferson Ornellas](https://www.instagram.com/jefferson.ornellas).

---

## Baixar

**[➜ Baixar a versão mais recente](../../releases/latest)**

Todas as versões, incluindo as anteriores, ficam disponíveis em **[Releases](../../releases)**. Se você prefere não atualizar, é só baixar o `.zip` da versão que quiser usar.

## Instalar

1. Baixe o arquivo `.zip` na página de releases
2. No WordPress, vá em **Plugins → Adicionar novo → Enviar plugin**
3. Escolha o `.zip` e clique em **Instalar agora**
4. Ative o plugin
5. Vá em **Configurações → MAAZAP** e preencha os dados de conexão da UzAPI
6. Clique em **Salvar alterações**, depois em **Testar conexão** e **Sincronizar grupos**

## Atualizar

Depois de instalado, o próprio WordPress avisa quando sai uma versão nova, igual a qualquer outro plugin. É só clicar em atualizar — não precisa baixar nada manualmente.

Quem preferir ficar numa versão específica pode simplesmente ignorar o aviso.

## O que faz

- Envia automaticamente toda notícia publicada para os seus grupos
- Três formatos: texto com prévia do link, foto da notícia com legenda, ou enquete
- Modelo de mensagem personalizável com etiquetas (`{titulo}`, `{link}`, `{resumo}`, `{categoria}`, `{autor}`, `{data}`, `{site}`)
- Painel com grupos, membros, envios, taxa de sucesso e gráfico de crescimento
- Envio manual escolhendo grupo a grupo
- Teste no seu próprio número antes de disparar
- Convite do grupo anexado à mensagem, para atrair novos membros
- Histórico de envios detalhado

## Cuidados

O plugin espaça os envios entre grupos, com variação aleatória, para reduzir o risco de bloqueio pelo WhatsApp. **Use sempre um número dedicado ao site, nunca o seu pessoal.**

## Requisitos

- WordPress 5.6 ou superior
- PHP 7.4 ou superior
- Uma conta na [UzAPI](https://uzapi.com.br) com um número de WhatsApp conectado

## Licença

GPLv2 ou posterior.
