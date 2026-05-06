import csv, re, datetime
from collections import defaultdict


def league_type(s):
    if "15 Mins" in s:
        return "15min"
    if "16 Mins" in s:
        return "16min"
    if "20 Mins" in s:
        return "20min"
    return "other"


def parse_goals(gs):
    out = []
    for half, minute, h, a in re.findall(r"(1H|2H)\s+(\d+)'\s*\((\d+)-(\d+)\)", gs):
        out.append({"half": half, "min": int(minute), "home": int(h), "away": int(a)})
    return out


def h1s(h1):
    out = []
    ph = pa = 0
    for g in h1:
        if g["home"] > ph:
            out.append("H")
        if g["away"] > pa:
            out.append("A")
        ph, pa = g["home"], g["away"]
    return "".join(out)


def switches(seq):
    return sum(1 for a, b in zip(seq, seq[1:]) if a != b)


def gaps(h1):
    if len(h1) < 2:
        return (99, 0)
    gs = [h1[i]["min"] - h1[i - 1]["min"] for i in range(1, len(h1))]
    return min(gs), max(gs)


def max_run(seq):
    best = cur = 0
    prev = None
    for c in seq:
        cur = cur + 1 if c == prev else 1
        prev = c
        best = max(best, cur)
    return best


def p51(m):
    return (
        m["league"] == "16min"
        and m["switches"] >= 2
        and m["first"] != 1
        and not (
            m["first"] == 0
            and m["last"] == 8
            and m["seq"] == "AHHAH"
            and m["score"] == "3-2"
            and m["min_gap"] == 0
        )
        and not (
            m["first"] == 2
            and m["last"] == 7
            and m["seq"] == "AHAAA"
            and m["score"] == "1-4"
            and m["min_gap"] == 0
            and m["max_run"] == 3
        )
    )


rows = []
with open("goal_log.csv", newline="", encoding="utf-8") as f:
    r = csv.DictReader(f)
    for row in r:
        if row.get("2h1") != "OK" or row.get("2h7") != "OK":
            continue
        goals = parse_goals(row["goals"])
        h1 = [g for g in goals if g["half"] == "1H"]
        h2 = [g for g in goals if g["half"] == "2H"]
        if not h1:
            continue
        seq = h1s(h1)
        mn, mx = gaps(h1)
        sh = h1[-1]["home"]
        sa = h1[-1]["away"]
        dt = datetime.datetime.strptime(row["datetime"], "%d/%m/%Y %H:%M")
        m = {
            "dt": row["datetime"],
            "league": league_type(row["league"]),
            "home": row["home_team"],
            "away": row["away_team"],
            "seq": seq,
            "score": f"{sh}-{sa}",
            "h1c": len(h1),
            "first": h1[0]["min"],
            "last": h1[-1]["min"],
            "span": h1[-1]["min"] - h1[0]["min"],
            "min_gap": mn,
            "max_gap": mx,
            "switches": switches(seq),
            "max_run": max_run(seq),
            "hit": len(h2) > 0,
            "hour": dt.hour,
            "minute": dt.minute,
            "dow": dt.weekday(),
        }
        rows.append(m)

base = [
    m for m in rows if m["league"] == "16min" and m["switches"] >= 2 and m["first"] != 1
]
print(
    "base",
    sum(m["hit"] for m in base),
    len(base),
    round(sum(m["hit"] for m in base) / len(base) * 100, 1),
)
print("p51", sum(m["hit"] for m in rows if p51(m)), sum(1 for m in rows if p51(m)))
print("base misses")
for m in base:
    if not m["hit"]:
        print(m)

# group non-p51 16min by structural keys high accuracy; require no teams/exact, sample >=3/4
cands = []
filters = []
# Build family conditions as lambdas names
for seq in sorted(set(m["seq"] for m in rows)):
    filters.append((f"seq={seq}", lambda m, seq=seq: m["seq"] == seq))
for score in sorted(set(m["score"] for m in rows)):
    filters.append((f"score={score}", lambda m, score=score: m["score"] == score))
for first in range(0, 9):
    filters.append((f"first={first}", lambda m, first=first: m["first"] == first))
for last in range(0, 10):
    filters.append((f"last={last}", lambda m, last=last: m["last"] == last))
for th in range(0, 8):
    filters.append((f"span>={th}", lambda m, th=th: m["span"] >= th))
for th in range(0, 8):
    filters.append((f"max_gap>={th}", lambda m, th=th: m["max_gap"] >= th))
for th in range(0, 4):
    filters.append((f"min_gap>={th}", lambda m, th=th: m["min_gap"] >= th))

# check combinations of 2-3 simple filters within 16min not p51 maybe additions
universe = [m for m in rows if m["league"] == "16min" and not p51(m) and m["h1c"] >= 2]
for i, (n1, f1) in enumerate(filters):
    for j, (n2, f2) in enumerate(filters[i + 1 :], i + 1):
        subset = [m for m in universe if f1(m) and f2(m)]
        if len(subset) >= 4:
            h = sum(m["hit"] for m in subset)
            pct = h / len(subset)
            if pct >= 0.95:
                cands.append((h, len(subset), pct, n1 + " & " + n2, subset))
# unique sort
cands = sorted(cands, key=lambda x: (x[0], x[2], x[1]), reverse=True)
print("\nTop candidate add branches (non-P51 16min):")
seen = set()
count = 0
for h, t, pct, name, subset in cands:
    sig = tuple(sorted((m["dt"], m["seq"], m["score"]) for m in subset))
    if sig in seen:
        continue
    seen.add(sig)
    count += 1
    print(f"{h}/{t} {pct * 100:.1f}% {name}")
    if count >= 25:
        break
