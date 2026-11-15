# language: pt
Funcionalidade: Decisão do cliente sobre o orçamento da saga
  O orçamento da saga nasce do comando GerarOrcamento. Aprovar ou recusar é o
  único passo humano: publica orcamento.aprovado ou orcamento.recusado na
  mesma saga, e carrega o trace que veio no comando.

  Cenário: Aprovar publica orcamento.aprovado na saga do comando
    Dado um orçamento gerado pela saga
    Quando eu aprovo esse orçamento
    Então a resposta deve ter o código "202"
    E o status da resposta é "aprovado"
    E foi publicado o evento "orcamento.aprovado" na mesma saga
    E o trace do evento "orcamento.aprovado" continua "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"

  Cenário: Recusar publica o motivo
    Dado um orçamento gerado pela saga
    Quando eu recuso esse orçamento com o motivo "achou caro"
    Então a resposta deve ter o código "202"
    E o status da resposta é "recusado"
    E foi publicado o evento "orcamento.recusado" na mesma saga
    E o motivo publicado em "orcamento.recusado" é "achou caro"

  Cenário: Decidir de novo é recusado e não publica outro evento
    Dado um orçamento gerado pela saga
    Quando eu aprovo esse orçamento
    E eu aprovo esse orçamento de novo
    Então a resposta deve ter o código "409"
    E o motivo da resposta é "ja_decidido"
    E o evento "orcamento.aprovado" foi publicado uma vez

  Cenário: Item fora do catálogo no comando publica orcamento.falhou
    Quando a saga manda gerar orçamento de um item fora do catálogo
    Então nenhum orçamento foi gravado
    E foi publicado o evento "orcamento.falhou" na mesma saga
