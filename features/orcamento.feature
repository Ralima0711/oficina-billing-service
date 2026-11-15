# language: pt
Funcionalidade: Orçamento avulso
  O POST /api/orcamentos grava o orçamento sem saga. O preço sai do catálogo
  local, não do corpo do pedido. Sem saga, a decisão do cliente não publica
  evento nenhum.

  Cenário: Gerar orçamento com itens do catálogo
    Quando eu gero um orçamento com os itens de exemplo
    Então a resposta deve ter o código "201"
    E o valor total do orçamento é "103"
    E a moeda do orçamento é "BRL"
    E nenhum evento foi publicado

  Cenário: Consultar o orçamento gerado
    Quando eu gero um orçamento com os itens de exemplo
    E eu consulto esse orçamento
    Então a resposta deve ter o código "200"
    E o valor total do orçamento é "103"

  Cenário: Item fora do catálogo é recusado
    Quando eu gero um orçamento com um item fora do catálogo
    Então a resposta deve ter o código "422"
    E nenhum evento foi publicado

  Cenário: Orçamento sem itens é recusado
    Quando eu gero um orçamento sem itens
    Então a resposta deve ter o código "422"

  Cenário: Decidir um orçamento avulso não publica evento
    Quando eu gero um orçamento com os itens de exemplo
    E eu aprovo esse orçamento
    Então a resposta deve ter o código "409"
    E o motivo da resposta é "sem_saga"
    E nenhum evento foi publicado
