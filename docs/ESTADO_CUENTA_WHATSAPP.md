# Compartir el estado de cuenta por WhatsApp

En **Reportes operativos → Estado de cuenta**, junto a *Descargar PDF* y *Enviar
por correo*, está **Compartir por WhatsApp**.

## Cómo funciona

1. Eliges el cliente y, si quieres, el rango de fechas.
2. Le das a **Compartir por WhatsApp**.
3. Se abre una ventana con el **número del tercero ya sugerido** —el celular si
   lo tiene, si no el fijo— que puedes cambiar para mandarlo a otro número.
4. Eliges en cuántos días caduca el enlace (7, 15, 30 o 90; por defecto 30).
5. Al confirmar se abre **tu WhatsApp Web en otra pestaña**, con el mensaje ya
   escrito y el enlace adentro. Solo le das enviar.

El mensaje va con el saldo del cliente en el texto, para que lo vea sin abrir
nada:

> Hola Carlos, le compartimos su estado de cuenta con Perfumería Aroma.
>
> A la fecha su saldo pendiente es de $148.800.
>
> Puede consultarlo aquí:
> https://pos.emprenddi.com/estado-cuenta/...

## Se manda un enlace, no un PDF

Dos razones:

- **Adjuntar un archivo** requeriría el API oficial de WhatsApp Business, que la
  empresa no tiene. Con el enlace basta con tener WhatsApp Web abierto.
- **El enlace muestra la cuenta del día en que se abre.** Si el cliente abona
  hoy y mira el enlace mañana, ve el saldo nuevo. Un PDF sería una foto vieja
  que además queda dando vueltas en el chat.

La página está pensada para celular: cada movimiento es una tarjeta con su
saldo corrido, y el saldo total va grande arriba. En pantalla ancha aparece la
tabla completa.

## Seguridad

Es información financiera de un tercero viajando por un chat que se puede
reenviar, así que:

- **El token es aleatorio de 40 caracteres.** No se adivina ni se llega al de
  otro cliente cambiando un número.
- **Caduca.** Pasada la fecha, el enlace responde 404. La página se lo dice al
  cliente al pie: «Este enlace es personal y deja de funcionar el …».
- **No se indexa.** La página lleva `noindex, nofollow`.
- **Cada envío genera un enlace nuevo**, así el reloj arranca de cero y queda
  registrado a qué número se mandó cada uno.
- **La ruta es pública, así que el filtro por empresa va a mano.** Sin usuario
  autenticado el filtro automático de este sistema no filtra nada; la empresa y
  el cliente salen del enlace, nunca del contexto. Hay pruebas dedicadas a eso.
- Límite de 60 peticiones por minuto.

Queda registrado cuándo se abrió y cuántas veces (`last_viewed_at`,
`view_count`), lo que sirve para saber si el cliente lo vio antes de volverlo a
llamar.

## El número

Se sugiere el del tercero pero se puede cambiar, que es lo normal: muchas veces
el que paga no es el que aparece en la ficha.

Si escribes **10 dígitos que empiezan por 3** se asume celular colombiano y se
le agrega el 57. Para cualquier otro país hay que escribir el indicativo. Un
número de menos de 7 dígitos se rechaza y no genera enlace: es preferible eso a
abrir un chat equivocado.

## Limitaciones conocidas

- **El envío lo hace la persona**, no el sistema: se abre WhatsApp Web con el
  mensaje listo pero alguien tiene que darle enviar. No hay envío automático ni
  masivo.
- **No hay pantalla para ver o revocar los enlaces generados.** Están en la
  tabla `customer_statement_shares` y caducan solos. Si alguna vez hace falta
  cortar uno antes de tiempo, hoy toca por base de datos.
- **Los enlaces vencidos no se borran.** No estorban —dejan de funcionar— pero
  con los años convendría un comando de limpieza como el de `audit:purge`.
