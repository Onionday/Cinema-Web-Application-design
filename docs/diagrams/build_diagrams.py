







import os
from xml.sax.saxutils import escape

OUT = os.path.dirname(os.path.abspath(__file__))
W = 720

PAPER = '#ffffff'
INK = '#1a1a1a'
MUTED = '#4b5563'
SOFT = '#6b7280'
RULE = 'rgba(26,26,26,0.16)'
ZONE = 'rgba(26,26,26,0.025)'
ACCENT = '#1f3a68'
ACCENT_TINT = 'rgba(31,58,104,0.08)'
SANS = "Arial, Helvetica, sans-serif"
MONO = "'Courier New', Courier, monospace"
SERIF = "'Times New Roman', Times, serif"


def page(slug, title, desc, h, body):
    return f'''<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{escape(title)}</title>
  <style>
    *, *::before, *::after {{ box-sizing: border-box; margin: 0; padding: 0; }}
    body {{ font-family: {SANS}; background: {PAPER}; color: {INK}; }}
    .diagram-container {{ width: 100%; overflow-x: auto; }}
    svg {{ width: 100%; min-width: {W}px; display: block; }}
    @media print {{ .diagram-container {{ overflow-x: visible; }} svg {{ min-width: 0; }} }}
  </style>
</head>
<body>
  <div class="diagram-container">
    <svg viewBox="0 0 {W} {h}" xmlns="http://www.w3.org/2000/svg" role="img" aria-labelledby="{slug}-title {slug}-desc">
      <title id="{slug}-title">{escape(title)}</title>
      <desc id="{slug}-desc">{escape(desc)}</desc>
      <defs>
        <marker id="arrow" markerWidth="8" markerHeight="6" refX="7" refY="3" orient="auto"><polygon points="0 0, 8 3, 0 6" fill="{MUTED}"/></marker>
        <marker id="arrow-accent" markerWidth="8" markerHeight="6" refX="7" refY="3" orient="auto"><polygon points="0 0, 8 3, 0 6" fill="{ACCENT}"/></marker>
        <marker id="arrow-link" markerWidth="8" markerHeight="6" refX="7" refY="3" orient="auto"><polygon points="0 0, 8 3, 0 6" fill="{ACCENT}"/></marker>
      </defs>
      <rect width="100%" height="100%" fill="{PAPER}"/>
{body}
    </svg>
  </div>
</body>
</html>
'''



def elbow(points, r=8, accent=False, dashed=False):

    d = 'M %d %d' % points[0]
    for i in range(1, len(points) - 1):
        (x0, y0), (x1, y1), (x2, y2) = points[i - 1], points[i], points[i + 1]
        dx0 = (x1 > x0) - (x1 < x0); dy0 = (y1 > y0) - (y1 < y0)
        dx1 = (x2 > x1) - (x2 < x1); dy1 = (y2 > y1) - (y2 < y1)
        d += ' L %d %d Q %d %d %d %d' % (x1 - dx0 * r, y1 - dy0 * r, x1, y1, x1 + dx1 * r, y1 + dy1 * r)
    d += ' L %d %d' % points[-1]
    col = ACCENT if accent else MUTED
    mk = 'arrow-accent' if accent else 'arrow'
    dash = ' stroke-dasharray="5,4"' if dashed else ''
    sw = '1.4' if accent else '1.1'
    return f'      <path d="{d}" fill="none" stroke="{col}" stroke-width="{sw}"{dash} marker-end="url(#{mk})"/>\n'


def line(x1, y1, x2, y2, accent=False, dashed=False):
    return elbow([(x1, y1), (x2, y2)], accent=accent, dashed=dashed)


def label(cx, cy, text, anchor='middle'):

    w = int(len(text) * 6.0 + 10)
    x = cx - w / 2 if anchor == 'middle' else (cx if anchor == 'start' else cx - w)
    tx = x + w / 2
    return (f'      <rect x="{x:.0f}" y="{cy - 7}" width="{w}" height="14" rx="2" fill="{PAPER}"/>\n'
            f'      <text x="{tx:.0f}" y="{cy + 3.5}" fill="{SOFT}" font-size="10" font-weight="700" font-family="{MONO}" '
            f'text-anchor="middle" letter-spacing="0.04em">{escape(text)}</text>\n')


def node(x, y, w, h, name, subs=(), focal=False, fill=None):
    stroke = ACCENT if focal else MUTED
    f = ACCENT_TINT if focal else (fill or PAPER)
    sw = '1.6' if focal else '1'
    out = (f'      <rect x="{x}" y="{y}" width="{w}" height="{h}" rx="6" fill="{PAPER}"/>\n'
           f'      <rect x="{x}" y="{y}" width="{w}" height="{h}" rx="6" fill="{f}" stroke="{stroke}" stroke-width="{sw}"/>\n')
    cx = x + w / 2
    n = len(subs)
    top = y + h / 2 - (n * 14) / 2 + 5
    out += (f'      <text x="{cx:.0f}" y="{top:.0f}" fill="{ACCENT if focal else INK}" font-size="14" font-weight="700" '
            f'font-family="{SANS}" text-anchor="middle">{escape(name)}</text>\n')
    for i, (s, kind) in enumerate(subs):
        fam, size = (MONO, 11) if kind == 'mono' else (SANS, 11.5)
        out += (f'      <text x="{cx:.0f}" y="{top + 16 + i * 14:.0f}" fill="{MUTED}" font-size="{size}" '
                f'font-family="{fam}" text-anchor="middle">{escape(s)}</text>\n')
    return out


def zone(x, y, w, h, eyebrow):
    return (f'      <rect x="{x}" y="{y}" width="{w}" height="{h}" rx="8" fill="{ZONE}" stroke="{RULE}" stroke-width="1" stroke-dasharray="4,3"/>\n'
            + eyebrow_text(x + 12, y + 18, eyebrow))


def eyebrow_text(x, y, text, anchor='start'):
    return (f'      <text x="{x}" y="{y}" fill="{MUTED}" font-size="10.5" font-weight="700" font-family="{MONO}" '
            f'text-anchor="{anchor}" letter-spacing="0.12em">{escape(text)}</text>\n')


def callout(x, y, lines, anchor='start'):
    out = ''
    for i, t in enumerate(lines):
        out += (f'      <text x="{x}" y="{y + i * 16}" fill="{MUTED}" font-size="13.5" font-style="italic" '
                f'font-family="{SERIF}" text-anchor="{anchor}">{escape(t)}</text>\n')
    return out


def hairline(y):
    return f'      <line x1="24" y1="{y}" x2="{W - 24}" y2="{y}" stroke="{RULE}" stroke-width="1"/>\n'



def figure1():
    b = ''
    b += eyebrow_text(24, 20, 'SITE MAP')

    b += line(360, 68, 360, 108)
    b += elbow([(280, 42), (12, 42), (12, 272), (36, 272)])
    b += elbow([(440, 42), (708, 42), (708, 272), (684, 272)])
    b += line(156, 162, 184, 162)

    b += zone(24, 108, 672, 96, 'OPEN TO EVERYONE')
    b += zone(36, 224, 284, 96, 'SIGNED-IN CUSTOMER')
    b += zone(544, 224, 140, 96, 'STAFF ROLE')

    b += node(280, 16, 160, 52, 'Home', [('index.php', 'mono')], focal=True)
    for x, n, f in [(40, 'Films', 'movies.php'), (184, 'Film details', 'movie.php'),
                    (312, 'Schedule', 'showtimes.php'), (440, 'Contact', 'contact.php'),
                    (568, 'Account', 'account.php')]:
        b += node(x, 136, 116, 52, n, [(f, 'mono')])
    b += node(48, 252, 124, 52, 'Short-list', [('preferences.php', 'mono')])
    b += node(184, 252, 124, 52, 'My bookings', [('mybookings.php', 'mono')])
    b += node(556, 252, 116, 52, 'Staff', [('admin.php', 'mono')])
    b += callout(432, 266, ['Every page is one click from Home', 'through the navigation bar.'], anchor='middle')


    b += hairline(344)
    b += eyebrow_text(24, 372, 'BOOKING STORYBOARD')
    b += callout(696, 372, ['Starts from a Book button on Home, Film details or Schedule'], anchor='end')
    mid = 422
    b += line(120, mid, 148, mid)
    b += line(268, mid, 292, mid)
    b += line(404, mid, 432, mid)
    b += line(552, mid, 584, mid)
    b += elbow([(348, 458), (348, 476), (208, 476), (208, 448)], dashed=True)
    b += label(134, mid - 15, 'ADD')
    b += label(280, mid - 15, 'PAY')
    b += label(418, mid - 15, 'YES')
    b += label(356, 466, 'NO', anchor='start')
    b += node(24, 396, 96, 52, 'Seat map', [('booking.php', 'mono')])
    b += node(148, 396, 120, 52, 'Short-list', [('preferences.php', 'mono')], focal=True)

    b += (f'      <polygon points="348,386 404,422 348,458 292,422" fill="{PAPER}"/>\n'
          f'      <polygon points="348,386 404,422 348,458 292,422" fill="{PAPER}" stroke="{MUTED}" stroke-width="1"/>\n'
          f'      <text x="348" y="419" fill="{INK}" font-size="11.5" font-weight="700" font-family="{SANS}" text-anchor="middle">Payment</text>\n'
          f'      <text x="348" y="434" fill="{INK}" font-size="11.5" font-weight="700" font-family="{SANS}" text-anchor="middle">approved?</text>\n')
    b += node(432, 396, 120, 52, 'E-ticket, email', [('confirmation.php', 'mono')])
    b += node(584, 396, 112, 52, 'My bookings', [('mybookings.php', 'mono')])
    b += callout(220, 498, ['Declined: nothing booked, short-list kept'])
    b += callout(696, 472, ['Cancel up to 2 h before;', 'seats go back on sale'], anchor='end')
    return page('fig1-sitemap', 'CineSphere site map and booking storyboard',
                'Site map of the eleven pages grouped by who may open them, and the booking task from seat map through short-list and payment to e-ticket and booking history.',
                516, b)



def figure4():
    xs = [96, 272, 448, 624]
    b = ''
    top, bottom = 68, 360
    for x in xs:
        b += f'      <line x1="{x}" y1="{top}" x2="{x}" y2="{bottom}" stroke="rgba(26,26,26,0.22)" stroke-width="1" stroke-dasharray="3,3"/>\n'

    for x, y0, y1 in [(272, 96, 272), (448, 128, 240), (624, 160, 208), (272, 304, 352)]:
        b += f'      <rect x="{x - 4}" y="{y0}" width="8" height="{y1 - y0}" fill="rgba(26,26,26,0.06)" stroke="{MUTED}" stroke-width="0.8"/>\n'
    msgs = [
        (0, 1, 104, '1 GET PAGE', False, False),
        (1, 2, 136, '2 RUN .PHP', False, False),
        (2, 3, 168, '3 PREPARED SQL', False, True),
        (3, 2, 200, '4 ROWS', True, False),
        (2, 1, 232, '5 HTML', True, False),
        (1, 0, 264, '6 PAGE', True, False),
        (0, 1, 312, 'GET CSS, JS', False, False),
        (1, 0, 344, 'STATIC FILE', True, False),
    ]
    for f, t, y, lab, dashed, acc in msgs:
        x1, x2 = xs[f], xs[t]
        if x2 > x1:
            x1 += 4 if f else 0; x2 -= 4
        else:
            x1 -= 4; x2 += 4 if t else 0
        b += line(x1, y, x2, y, accent=acc, dashed=dashed)
        b += label((xs[f] + xs[t]) / 2, y - 14, lab)
    actors = [('Browser', 'PHPSESSID cookie', False), ('Apache (XAMPP)', 'serves static files', False),
              ('PHP engine', 'pages + handlers', True), ('MySQL', '13 tables + 1 view', False)]
    for x, (n, s, foc) in zip(xs, actors):
        b += node(x - 76, 16, 152, 52, n, [(s, 'mono')], focal=foc)
    b += callout(104, 384, ['CSS, JavaScript and images come straight from Apache; PHP is not involved.'])

    b += hairline(404)
    b += eyebrow_text(24, 428, 'LEGEND')
    b += f'      <line x1="104" y1="424" x2="140" y2="424" stroke="{MUTED}" stroke-width="1.1" marker-end="url(#arrow)"/>\n'
    b += f'      <text x="150" y="428" fill="{MUTED}" font-size="11.5" font-family="{SANS}">Request</text>\n'
    b += f'      <line x1="232" y1="424" x2="268" y2="424" stroke="{MUTED}" stroke-width="1.1" stroke-dasharray="5,4" marker-end="url(#arrow)"/>\n'
    b += f'      <text x="278" y="428" fill="{MUTED}" font-size="11.5" font-family="{SANS}">Response</text>\n'
    b += f'      <rect x="364" y="418" width="8" height="12" fill="rgba(26,26,26,0.06)" stroke="{MUTED}" stroke-width="0.8"/>\n'
    b += f'      <text x="380" y="428" fill="{MUTED}" font-size="11.5" font-family="{SANS}">Busy handling the request</text>\n'
    return page('fig4-architecture', 'One request through the CineSphere stack',
                'Sequence of one page request: the browser asks Apache, Apache hands the PHP file to the PHP engine, PHP runs a prepared query on MySQL, and the HTML travels back, while static files come straight from Apache.',
                444, b)



def figure5():
    L, R = 52, 412
    NW, NH = 256, 64
    lcx, rcx = L + NW // 2, R + NW // 2
    ys = [56 + k * 76 for k in range(5)]
    b = ''

    b += f'      <line x1="360" y1="24" x2="360" y2="436" stroke="{RULE}" stroke-width="1"/>\n'
    b += eyebrow_text(lcx, 36, 'DISPLAY PAGES  ·  GET', anchor='middle')
    b += eyebrow_text(rcx, 36, 'FORM HANDLERS  ·  POST', anchor='middle')

    mids = [y + NH // 2 for y in ys]
    b += elbow([(L + NW, mids[0]), (rcx, mids[0]), (rcx, ys[1])])
    b += elbow([(R, mids[1]), (lcx, mids[1]), (lcx, ys[2])])
    b += elbow([(L + NW, mids[2]), (rcx, mids[2]), (rcx, ys[3])])
    b += elbow([(R, mids[3] + 10), (lcx, mids[3] + 10), (lcx, ys[4])], accent=True)
    b += elbow([(R, mids[3] - 12), (lcx + 64, mids[3] - 12), (lcx + 64, ys[2] + NH)], dashed=True)
    b += label(380, mids[0] - 14, 'POST')
    b += label(340, mids[1] - 14, '302 REDIRECT')
    b += label(380, mids[2] - 14, 'POST')
    b += label(296, mids[3] - 26, 'DECLINED')
    b += label(340, mids[3] + 24, 'PAID · 302')

    b += node(L, ys[0], NW, NH, 'Seat map', [('booking.php', 'mono'), ('seats drawn from the database', 'sans')])
    b += node(R, ys[1], NW, NH, 'Save the choice', [('process_preference.php', 'mono'), ('CSRF, validate, INSERT', 'sans')])
    b += node(L, ys[2], NW, NH, 'Short-list', [('preferences.php', 'mono'), ('re-check seats, tick entries, pay', 'sans')])
    b += node(R, ys[3], NW, NH, 'Checkout', [('process_booking.php', 'mono'), ('quote, pay, transaction, email', 'sans')], focal=True)
    b += node(L, ys[4], NW, NH, 'E-tickets', [('confirmation.php', 'mono'), ('reads the booking; safe to refresh', 'sans')])
    b += callout(rcx, ys[4] + 26, ['Handlers print no HTML: each one', 'writes, then redirects to a page.'], anchor='middle')
    return page('fig5-pipeline', 'CineSphere booking pipeline',
                'Booking pipeline in two lanes: display pages answer GET requests and only read, while form handlers receive POST requests, write to the database and redirect, from seat map to e-ticket.',
                444, b)


for name, fn in [('fig1-sitemap.html', figure1), ('fig4-architecture.html', figure4), ('fig5-pipeline.html', figure5)]:
    with open(os.path.join(OUT, name), 'w', encoding='utf8') as fh:
        fh.write(fn())
    print('wrote', name)
