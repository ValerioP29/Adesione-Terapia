# QA - Promemoria CRUD

## Test manuali
1) Creazione one_shot: crea un promemoria una tantum con data/ora valida e verifica `success=true` e `next_due_at` uguale a `first_due_at`.
2) Creazione biweekly interval_value=2: crea un promemoria bisettimanale con intervallo 2 e controlla che `frequency=biweekly` e `interval_value=2`.
3) Creazione monthly day=31: crea un promemoria mensile il giorno 31 e verifica che la scadenza successiva (dopo mark_done) usi l’ultimo giorno del mese successivo se il 31 non esiste.
4) Mark_done monthly: imposta un promemoria mensile con `first_due_at` 2026-01-01 (stessa ora) e verifica che dopo “Segna fatto” `next_due_at` diventi 2026-02-01.
