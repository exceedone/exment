{{--
    Thumbnail of a chart type for the toolbar's type picker (toolbar.blade): a 64×40 sketch of
    the chart's shape, so a viewer sees what a type looks like before picking it.
    $type  a ChartType value
--}}
@php
    $c = ['#ff6384', '#36a2eb', '#ffce56', '#339900', '#ff6633'];
    $axis = '<line x1="3" y1="37" x2="62" y2="37" stroke="#d0cfe0"/>';
    $bars = function (array $xs, array $tops, $color) {
        $out = '';
        foreach ($xs as $i => $x) {
            $out .= '<rect x="' . $x . '" y="' . $tops[$i] . '" width="8" height="' . (36 - $tops[$i]) . '" fill="' . $color . '"/>';
        }
        return $out;
    };
    switch ($type) {
        case 'bar':
            $svg = $axis . $bars([6, 18, 30, 42, 54], [18, 8, 14, 4, 22], $c[1]);
            break;
        case 'line':
            $svg = $axis . '<polyline points="4,30 16,18 28,24 40,8 52,14 60,6" fill="none" stroke="' . $c[1] . '" stroke-width="2.5"/>'
                . '<circle cx="16" cy="18" r="2.2" fill="' . $c[1] . '"/><circle cx="28" cy="24" r="2.2" fill="' . $c[1] . '"/><circle cx="40" cy="8" r="2.2" fill="' . $c[1] . '"/><circle cx="52" cy="14" r="2.2" fill="' . $c[1] . '"/>';
            break;
        case 'pie':
            $svg = '<circle cx="32" cy="20" r="17" fill="' . $c[2] . '"/><path d="M32,20 L32,3 A17,17 0 0,1 48.2,25.3 Z" fill="' . $c[0] . '"/><path d="M32,20 L48.2,25.3 A17,17 0 0,1 22,33.8 Z" fill="' . $c[1] . '"/>';
            break;
        case 'hbar':
            $svg = '<rect x="6" y="5" width="50" height="6" fill="' . $c[1] . '"/><rect x="6" y="13" width="34" height="6" fill="' . $c[1] . '"/><rect x="6" y="21" width="42" height="6" fill="' . $c[1] . '"/><rect x="6" y="29" width="22" height="6" fill="' . $c[1] . '"/>';
            break;
        case 'area':
            $svg = $axis . '<path d="M4,36 L4,26 L16,16 L28,22 L40,8 L52,14 L60,6 L60,36 Z" fill="' . $c[1] . '" opacity=".45"/><polyline points="4,26 16,16 28,22 40,8 52,14 60,6" fill="none" stroke="' . $c[1] . '" stroke-width="2"/>';
            break;
        case 'doughnut':
            $svg = '<circle cx="32" cy="20" r="14" fill="none" stroke="' . $c[2] . '" stroke-width="8"/><path d="M32,6 A14,14 0 0,1 45.3,24.3" fill="none" stroke="' . $c[0] . '" stroke-width="8"/><path d="M45.3,24.3 A14,14 0 0,1 23.8,31.3" fill="none" stroke="' . $c[1] . '" stroke-width="8"/>';
            break;
        case 'radar':
            $svg = '<polygon points="32,3 49,15 43,35 21,35 15,15" fill="none" stroke="#d0cfe0"/><polygon points="32,11 41,17 38,28 26,28 23,17" fill="none" stroke="#d0cfe0"/><polygon points="32,6 45,16 37,31 24,30 20,17" fill="' . $c[1] . '" fill-opacity=".45" stroke="' . $c[1] . '" stroke-width="1.5"/>';
            break;
        case 'funnel':
            $svg = '<polygon points="8,4 56,4 50,12 14,12" fill="' . $c[0] . '"/><polygon points="15,14 49,14 44,22 20,22" fill="' . $c[1] . '"/><polygon points="21,24 43,24 39,31 25,31" fill="' . $c[2] . '"/><polygon points="26,33 38,33 35,38 29,38" fill="' . $c[3] . '"/>';
            break;
        case 'gauge':
            $svg = '<path d="M10,32 A22,22 0 0,1 54,32" fill="none" stroke="#e6e4f0" stroke-width="7"/><path d="M10,32 A22,22 0 0,1 44,13" fill="none" stroke="' . $c[1] . '" stroke-width="7"/><line x1="32" y1="32" x2="42" y2="17" stroke="#8a89a0" stroke-width="2"/><circle cx="32" cy="32" r="3" fill="#8a89a0"/>';
            break;
        case 'scatter':
            $svg = $axis;
            foreach ([[8, 30], [14, 22], [20, 26], [26, 16], [30, 24], [36, 12], [42, 18], [48, 8], [54, 14], [58, 6], [22, 32], [44, 26]] as [$x, $y]) {
                $svg .= '<circle cx="' . $x . '" cy="' . $y . '" r="2.6" fill="' . $c[1] . '"/>';
            }
            break;
        case 'combo':
            // bars on the left axis, a line of another value over them, its own axis on the right
            $svg = $axis . '<line x1="61" y1="3" x2="61" y2="37" stroke="#d0cfe0"/>' . $bars([6, 18, 30, 42], [18, 10, 14, 6], $c[1])
                . '<polyline points="10,28 22,14 34,20 46,8 56,12" fill="none" stroke="' . $c[0] . '" stroke-width="2.5"/>';
            foreach ([[10, 28], [22, 14], [34, 20], [46, 8], [56, 12]] as [$x, $y]) {
                $svg .= '<circle cx="' . $x . '" cy="' . $y . '" r="2.2" fill="#fff" stroke="' . $c[0] . '" stroke-width="1.5"/>';
            }
            break;
        case 'mbar':
            $svg = $axis . $bars([6, 27, 48], [18, 14, 20], $c[0]) . $bars([11, 32, 53], [10, 6, 12], $c[1]) . $bars([16, 37, 58], [22, 18, 24], $c[2]);
            break;
        case 'sbar':
            $svg = $axis;
            foreach ([8, 25, 42] as $i => $x) {
                $svg .= '<rect x="' . $x . '" y="' . (26 - $i * 2) . '" width="12" height="' . (10 + $i * 2) . '" fill="' . $c[0] . '"/>'
                    . '<rect x="' . $x . '" y="' . (16 - $i * 3) . '" width="12" height="' . (10 + $i) . '" fill="' . $c[1] . '"/>'
                    . '<rect x="' . $x . '" y="' . (6 - $i * 2) . '" width="12" height="' . (10 + $i) . '" fill="' . $c[2] . '"/>';
            }
            break;
        case 'sbar100':
            // every column as tall as the plot, split in shares
            $svg = $axis;
            foreach ([[8, [14, 10, 8]], [25, [8, 16, 8]], [42, [18, 6, 8]]] as [$x, $parts]) {
                $y = 36;
                foreach ($parts as $i => $h) {
                    $y -= $h;
                    $svg .= '<rect x="' . $x . '" y="' . $y . '" width="12" height="' . $h . '" fill="' . $c[$i] . '"/>';
                }
            }
            break;
        case 'shbar100':
            // every bar as long as the plot, split in shares, the first item on top
            $svg = '<line x1="5" y1="3" x2="5" y2="37" stroke="#d0cfe0"/>';
            foreach ([[5, [22, 18, 12]], [14, [14, 26, 12]], [23, [30, 12, 10]], [32, [10, 20, 22]]] as [$y, $parts]) {
                $x = 6;
                foreach ($parts as $i => $w) {
                    $svg .= '<rect x="' . $x . '" y="' . $y . '" width="' . $w . '" height="6" fill="' . $c[$i] . '"/>';
                    $x += $w;
                }
            }
            break;
        case 'mline':
            $svg = $axis . '<polyline points="4,30 18,22 32,26 46,12 60,16" fill="none" stroke="' . $c[0] . '" stroke-width="2.5"/><polyline points="4,20 18,28 32,14 46,20 60,6" fill="none" stroke="' . $c[1] . '" stroke-width="2.5"/><polyline points="4,34 18,32 32,30 46,28 60,26" fill="none" stroke="' . $c[2] . '" stroke-width="2.5"/>';
            break;
        case 'heatmap':
            $svg = '';
            for ($r = 0; $r < 4; $r++) {
                for ($col = 0; $col < 6; $col++) {
                    $svg .= '<rect x="' . (4 + $col * 10) . '" y="' . (3 + $r * 9) . '" width="9" height="8" fill="#5b6ef5" opacity="' . round(0.15 + (($r * 3 + $col * 5) % 7) / 8, 2) . '"/>';
                }
            }
            break;
        case 'sarea':
            $svg = $axis . '<path d="M4,36 L4,26 L20,22 L36,24 L52,16 L60,18 L60,36 Z" fill="' . $c[0] . '"/><path d="M4,26 L20,22 L36,24 L52,16 L60,18 L60,10 L52,8 L36,14 L20,12 L4,16 Z" fill="' . $c[1] . '"/><path d="M4,16 L20,12 L36,14 L52,8 L60,10 L60,4 L52,3 L36,6 L20,5 L4,8 Z" fill="' . $c[2] . '"/>';
            break;
        case 'treemap':
            $svg = '<rect x="4" y="3" width="30" height="34" fill="' . $c[1] . '"/><rect x="35" y="3" width="25" height="18" fill="' . $c[0] . '"/><rect x="35" y="22" width="13" height="15" fill="' . $c[2] . '"/><rect x="49" y="22" width="11" height="15" fill="' . $c[3] . '"/>';
            break;
        case 'sunburst':
            $svg = '<circle cx="32" cy="20" r="18" fill="' . $c[2] . '"/><path d="M32,2 A18,18 0 0,1 50,20 L32,20 Z" fill="' . $c[0] . '"/><path d="M50,20 A18,18 0 0,1 22,35.6 L32,20 Z" fill="' . $c[1] . '"/><circle cx="32" cy="20" r="9" fill="#fff"/><circle cx="32" cy="20" r="6" fill="' . $c[4] . '"/>';
            break;
        case 'boxplot':
            $svg = $axis;
            foreach ([10, 28, 46] as $i => $x) {
                $svg .= '<line x1="' . ($x + 4) . '" y1="' . (4 + $i * 3) . '" x2="' . ($x + 4) . '" y2="' . (36 - $i) . '" stroke="#8a89a0" stroke-width="1.5"/>'
                    . '<rect x="' . $x . '" y="' . (12 + $i * 3) . '" width="8" height="' . (14 - $i) . '" fill="#fff" stroke="' . $c[1] . '" stroke-width="2"/>'
                    . '<line x1="' . $x . '" y1="' . (19 + $i * 2) . '" x2="' . ($x + 8) . '" y2="' . (19 + $i * 2) . '" stroke="' . $c[1] . '" stroke-width="2"/>';
            }
            break;
        default:
            $svg = $axis . $bars([6, 18, 30, 42, 54], [18, 8, 14, 4, 22], '#b9b8cc');
    }
@endphp
<svg class="ctp-icon" viewBox="0 0 64 40" aria-hidden="true">{!! $svg !!}</svg>
