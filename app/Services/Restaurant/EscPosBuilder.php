<?php

namespace App\Services\Restaurant;

/**
 * Builder minimal de comandos ESC/POS para impresoras térmicas.
 * Genera un binary string que se manda a la impresora vía TCP.
 *
 * Comandos cubiertos (subset Epson / Star compatible):
 *  - inicialización (ESC @)
 *  - texto + line feeds
 *  - alineación (ESC a 0/1/2)
 *  - bold (ESC E 0/1)
 *  - tamaño (GS ! con multiplicadores 0..7)
 *  - underline (ESC - 0/1)
 *  - corte de papel (GS V 0)
 *  - abrir cajón (ESC p 0 pulse_on pulse_off)
 *  - line separator
 *
 * No requiere mike42/escpos-php — todo nativo.
 */
class EscPosBuilder
{
    protected string $buffer = '';
    protected int $columns;

    public function __construct(int $columns = 48)
    {
        $this->columns = max(20, min(80, $columns));
        $this->reset();
    }

    public function reset(): static
    {
        // ESC @ → inicializa impresora (limpia formato)
        $this->buffer .= chr(0x1B).chr(0x40);
        return $this;
    }

    public function text(string $text): static
    {
        // Forzar codificación CP858 (Epson Latin) para acentos básicos.
        // ESC t 19 → CP858. Si la impresora no lo soporta, ignora.
        $encoded = @iconv('UTF-8', 'CP858//TRANSLIT//IGNORE', $text);
        $this->buffer .= $encoded !== false ? $encoded : $text;
        return $this;
    }

    public function line(string $text = ''): static
    {
        return $this->text($text)->lf();
    }

    public function lf(int $count = 1): static
    {
        $this->buffer .= str_repeat(chr(0x0A), max(1, $count));
        return $this;
    }

    public function alignLeft(): static   { $this->buffer .= chr(0x1B).'a'.chr(0); return $this; }
    public function alignCenter(): static { $this->buffer .= chr(0x1B).'a'.chr(1); return $this; }
    public function alignRight(): static  { $this->buffer .= chr(0x1B).'a'.chr(2); return $this; }

    public function bold(bool $on = true): static
    {
        $this->buffer .= chr(0x1B).'E'.chr($on ? 1 : 0);
        return $this;
    }

    public function underline(bool $on = true): static
    {
        $this->buffer .= chr(0x1B).'-'.chr($on ? 1 : 0);
        return $this;
    }

    /**
     * Tamaño: width 1..8, height 1..8.
     */
    public function size(int $width = 1, int $height = 1): static
    {
        $w = max(1, min(8, $width)) - 1;
        $h = max(1, min(8, $height)) - 1;
        // GS ! con nibble alto=width, bajo=height
        $this->buffer .= chr(0x1D).'!'.chr(($w << 4) | $h);
        return $this;
    }

    public function separator(string $char = '-'): static
    {
        return $this->line(str_repeat($char, $this->columns));
    }

    /**
     * Imprime dos columnas: izquierda y derecha alineadas a los bordes
     * del papel. Útil para "cant x producto ........... $valor".
     */
    public function twoCols(string $left, string $right): static
    {
        $leftLen = mb_strlen($left);
        $rightLen = mb_strlen($right);
        $available = $this->columns - $leftLen - $rightLen;

        if ($available < 1) {
            // No cabe en una línea: print left, line break, right alineado derecha.
            $this->line($left);
            $this->alignRight()->line($right)->alignLeft();
            return $this;
        }

        return $this->line($left . str_repeat(' ', $available) . $right);
    }

    /**
     * Corta el papel. GS V 0 = full cut; GS V 1 = partial cut.
     */
    public function cut(bool $full = true): static
    {
        // Avanza un poco antes de cortar para que se vea el último contenido
        $this->lf(3);
        $this->buffer .= chr(0x1D).'V'.chr($full ? 0 : 1);
        return $this;
    }

    /**
     * Abre el cajón de monedas (pin 2 o 5 — Epson pin 2 = primer cajón).
     * Pulse 50ms encendido, 50ms apagado.
     */
    public function openDrawer(int $pin = 0): static
    {
        // ESC p m t1 t2 (m=0 pin2, m=1 pin5)
        $this->buffer .= chr(0x1B).'p'.chr($pin).chr(50).chr(50);
        return $this;
    }

    public function getBytes(): string
    {
        return $this->buffer;
    }
}
