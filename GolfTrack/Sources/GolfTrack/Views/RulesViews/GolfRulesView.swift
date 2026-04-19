import SwiftUI

struct GolfRulesView: View {
    @State private var expandedSection: String? = nil

    var body: some View {
        List {
            ForEach(GolfRulesContent.sections) { section in
                Section {
                    DisclosureGroup(
                        isExpanded: Binding(
                            get: { expandedSection == section.id },
                            set: { expandedSection = $0 ? section.id : nil }
                        )
                    ) {
                        VStack(alignment: .leading, spacing: 14) {
                            ForEach(section.items) { item in
                                GolfRuleRow(item: item)
                            }
                        }
                        .padding(.vertical, 8)
                    } label: {
                        Label(section.title, systemImage: section.icon)
                            .font(.headline)
                    }
                }
            }

            Section {
                VStack(alignment: .leading, spacing: 6) {
                    Text("Official Rulebook")
                        .font(.caption.bold())
                    Text("These rules follow the USGA / R&A Rules of Golf (2023 edition). For tournament play always defer to the official rules at usga.org.")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
                .padding(.vertical, 4)
            }
        }
        .navigationTitle("Rules of Golf")
#if os(iOS)
        .navigationBarTitleDisplayMode(.large)
#endif
    }
}

// MARK: - Row

private struct GolfRuleRow: View {
    let item: GolfRuleItem

    var body: some View {
        HStack(alignment: .top, spacing: 12) {
            if let badge = item.badge {
                Text(badge)
                    .font(.system(size: 11, weight: .bold))
                    .foregroundStyle(.white)
                    .padding(.horizontal, 6)
                    .padding(.vertical, 3)
                    .background(item.badgeColor)
                    .clipShape(Capsule())
                    .padding(.top, 1)
            } else {
                Circle()
                    .fill(Color.green)
                    .frame(width: 7, height: 7)
                    .padding(.top, 6)
            }
            VStack(alignment: .leading, spacing: 3) {
                if let title = item.title {
                    Text(title).font(.callout.bold())
                }
                Text(item.body)
                    .font(.callout)
                    .foregroundStyle(item.title != nil ? .secondary : .primary)
            }
        }
    }
}

// MARK: - Model

struct GolfRulesSection: Identifiable {
    let id:    String
    let title: String
    let icon:  String
    let items: [GolfRuleItem]
}

struct GolfRuleItem: Identifiable {
    let id         = UUID()
    var title:     String? = nil
    var badge:     String? = nil
    var badgeColor: Color  = .green
    var body:      String
}

// MARK: - Content

enum GolfRulesContent {
    static let sections: [GolfRulesSection] = [
        basics, scoring, teeing, playingTheBall, hazards, green, penalties, etiquette
    ]

    static let basics = GolfRulesSection(
        id: "basics", title: "The Game", icon: "flag.fill",
        items: [
            .init(body: "Golf is played on a course of 9 or 18 holes. The object is to play each hole from the teeing area into the hole on the putting green in as few strokes as possible."),
            .init(body: "A standard round is 18 holes. A half-round is 9 holes. Par for a full course is typically 70–72."),
            .init(title: "Par", body: "Par is the number of strokes an expert golfer is expected to take on a hole. Par-3s are short holes (up to ~250 yards), par-4s are mid-length (~251–470 yards), and par-5s are long holes (471+ yards). Some courses have par-6 holes."),
            .init(title: "Order of Play", body: "On the first tee, the order is determined by draw, lot, or agreement. After that, the player farthest from the hole plays first (called 'honors'). On the tee of subsequent holes, the player with the lowest score on the previous hole has the honor."),
            .init(title: "Equipment", body: "A player may carry a maximum of 14 clubs during a round. Using more than 14 clubs results in a penalty of 2 strokes per hole where the violation occurred (max 4 strokes per round in stroke play)."),
        ]
    )

    static let scoring = GolfRulesSection(
        id: "scoring", title: "Scoring", icon: "number.circle.fill",
        items: [
            .init(title: "Stroke Play", body: "The most common format. Every stroke counts. Your score for the round is the total number of strokes taken. Lower is better."),
            .init(badge: "−2", badgeColor: .yellow, body: "Eagle — 2 strokes under par on a hole (e.g., a 3 on a par-5)."),
            .init(badge: "−1", badgeColor: .yellow, body: "Birdie — 1 stroke under par (e.g., a 3 on a par-4)."),
            .init(badge: "E", badgeColor: .green, body: "Par — equal to par for the hole."),
            .init(badge: "+1", badgeColor: .blue, body: "Bogey — 1 stroke over par."),
            .init(badge: "+2", badgeColor: .red, body: "Double Bogey — 2 strokes over par."),
            .init(badge: "+3", badgeColor: .red, body: "Triple Bogey — 3 strokes over par. Also called a 'snowman' when the score is 8 (shaped like an 8)."),
            .init(badge: "ACE", badgeColor: .purple, body: "Hole-in-one — the ball goes into the hole from the tee shot. Extremely rare; occurs in roughly 1 in 12,500 rounds for amateurs."),
            .init(title: "Match Play", body: "Players compete hole by hole. Win a hole (lower score) and you go 1-up. Tie a hole and it's halved. The player who is up by more holes than remain wins. Abbreviated as 2&1 (won with 2 holes up, 1 to play)."),
            .init(title: "Handicap", body: "A numerical measure of a golfer's ability. Lower handicap = better player. World Handicap System (WHS): calculated from the best 8 of your last 20 score differentials × 0.96. GolfTrack computes this automatically."),
            .init(title: "Score Differential", body: "The formula used to calculate your handicap: (Gross Score − Course Rating) × 113 ÷ Slope Rating. Course Rating reflects the difficulty for a scratch golfer; Slope Rating (55–155, avg 113) reflects relative difficulty for a bogey golfer."),
        ]
    )

    static let teeing = GolfRulesSection(
        id: "teeing", title: "Teeing Area", icon: "arrow.up.forward.circle.fill",
        items: [
            .init(body: "Play begins from the teeing area on each hole. The ball must be teed between the tee markers and no more than two club-lengths behind them."),
            .init(body: "You may stand outside the teeing area as long as the ball is within it."),
            .init(title: "Tee Colors", body: "Different tee boxes have different yardages. Common colors: Black/Gold (championship/longest), Blue (men's standard), White (middle), Yellow/Red (forward/ladies). Choose the tees appropriate for your skill level."),
            .init(title: "Whiff", body: "If you swing and miss the ball on the tee (or anywhere), it counts as one stroke. You don't lose the right to re-tee — just play it where it lies."),
            .init(badge: "NOTE", badgeColor: .orange, body: "If you knock the ball off the tee while addressing it (without intending a stroke), there is no penalty and you simply re-tee it."),
        ]
    )

    static let playingTheBall = GolfRulesSection(
        id: "playingTheBall", title: "Playing the Ball", icon: "figure.golf",
        items: [
            .init(title: "Play It As It Lies", body: "The fundamental rule of golf: play the ball from wherever it comes to rest. You must not improve your lie, stance, or swing area — no pressing down grass, no breaking branches."),
            .init(title: "Identifying Your Ball", body: "You must be able to identify your ball. Mark it with a pen. If you cannot identify it and it was not in a penalty area, it is a lost ball and you must proceed under the stroke-and-distance rule."),
            .init(title: "Lost Ball", body: "If a ball cannot be found within 3 minutes of searching, it is lost. You must return to where you last played from, add 1 penalty stroke, and play again (stroke-and-distance relief)."),
            .init(title: "Unplayable Ball", body: "You may declare your ball unplayable anywhere on the course (except in a penalty area). Options:\n• Stroke-and-distance: go back where you last played (+1 stroke)\n• Drop within 2 club-lengths of the spot, no closer to hole (+1 stroke)\n• Drop on a line from the hole through the ball, going back as far as you like (+1 stroke)"),
            .init(title: "Loose Impediments", body: "You may remove natural loose objects (leaves, twigs, stones, acorns) anywhere on the course without penalty — including bunkers and penalty areas."),
            .init(title: "Movable Obstructions", body: "Man-made objects (rakes, benches, stakes) may be moved. If the ball moves during removal, replace it without penalty."),
            .init(badge: "TIP", badgeColor: .green, body: "Use a 'provisional ball' when your shot might be lost or out of bounds. Announce it as provisional, play it, then go look. If the original is found in bounds, play it. If not, play the provisional from where it lies with the penalty already counted."),
        ]
    )

    static let hazards = GolfRulesSection(
        id: "hazards", title: "Penalty Areas & OB", icon: "exclamationmark.triangle.fill",
        items: [
            .init(title: "Penalty Areas (Water)", body: "Marked with red or yellow stakes/lines. You may play from inside a penalty area without penalty — but if you cannot or choose not to, you take 1 penalty stroke and use one of the relief options."),
            .init(badge: "YELLOW", badgeColor: .yellow, body: "Yellow penalty area: two options — (1) stroke-and-distance back to where you last played, or (2) drop behind the penalty area on a line from the hole through where the ball entered."),
            .init(badge: "RED", badgeColor: .red, body: "Red penalty area: the same two options as yellow, PLUS (3) drop within 2 club-lengths of the reference point, no closer to the hole."),
            .init(title: "Out of Bounds (OB)", body: "Marked by white stakes or lines. A ball entirely beyond the boundary is OB. Penalty: stroke-and-distance — 1 stroke penalty, replay from where you last played."),
            .init(title: "Local Rule: Drop Zone", body: "Many courses use a local rule allowing a drop in a designated drop zone near the penalty area for 2 strokes total instead of stroke-and-distance. Check the scorecard."),
            .init(title: "Bunkers", body: "You may not ground your club (touch the sand) before making your stroke. You cannot remove loose impediments in a bunker — except stones, which local rule may permit. Unplayable from a bunker: 2-stroke option to drop outside."),
        ]
    )

    static let green = GolfRulesSection(
        id: "green", title: "Putting Green", icon: "circle.dotted",
        items: [
            .init(body: "The putting green is the specially prepared area around each hole. The hole is 4.25 inches in diameter and at least 4 inches deep."),
            .init(title: "Mark Your Ball", body: "On the green, you may mark, lift, and clean your ball. Place a coin or ball marker directly behind (away from the hole) the ball, then lift it. Replace it on the same spot before putting."),
            .init(title: "Repair the Green", body: "You may repair any damage on the green — ball marks, spike marks, scuffs — without penalty. Use a repair tool or tee. Fix it: push inward and press down."),
            .init(title: "The Flagstick", body: "You may leave the flagstick in while putting from anywhere on the green (or off it). There is no penalty if the ball hits the flagstick while putting. You may also have it removed or attended."),
            .init(title: "Conceded Putts", body: "In match play, a player may concede an opponent's next stroke at any time (the opponent picks up without holing out). Concessions cannot be declined and cannot be withdrawn."),
            .init(badge: "TIP", badgeColor: .green, body: "Always repair your ball mark when you reach the green — and repair one more. It takes only 10 seconds and keeps the course in great condition for everyone."),
            .init(title: "Putting Order", body: "The player farthest from the hole putts first. In recreational golf, 'ready golf' is encouraged — whoever is ready plays to keep pace."),
        ]
    )

    static let penalties = GolfRulesSection(
        id: "penalties", title: "Common Penalties", icon: "plus.circle.fill",
        items: [
            .init(body: "Penalty strokes are added to your score and do not count as a stroke played. They exist to account for relief taken or rules violated."),
            .init(badge: "+1", badgeColor: .orange, body: "Ball in penalty area (water) — taking relief outside the area."),
            .init(badge: "+1", badgeColor: .orange, body: "Unplayable ball — taking any of the three relief options."),
            .init(badge: "+1", badgeColor: .orange, body: "Lost ball or out of bounds — must also replay from previous spot (stroke-and-distance = net +2 effect)."),
            .init(badge: "+1", badgeColor: .orange, body: "Provisional ball becomes ball in play (if original is lost/OB) — the penalty is already built into the stroke-and-distance count."),
            .init(badge: "+2", badgeColor: .red, body: "Grounding the club in a bunker before the stroke."),
            .init(badge: "+2", badgeColor: .red, body: "Playing from the wrong place (wrong spot, not your ball, wrong teeing area)."),
            .init(badge: "+2", badgeColor: .red, body: "Improving your lie, line of play, or swing area (moving branches, pressing grass down)."),
            .init(badge: "+2", badgeColor: .red, body: "Slow play — failure to play within the time allowed (course dependent; common in tournament play)."),
            .init(badge: "DQ", badgeColor: Color(red: 0.6, green: 0, blue: 0), body: "Disqualification: signing an incorrect scorecard, using non-conforming equipment, or serious rules violations."),
        ]
    )

    static let etiquette = GolfRulesSection(
        id: "etiquette", title: "Etiquette & Pace", icon: "person.2.fill",
        items: [
            .init(title: "Pace of Play", body: "Ready golf is encouraged in recreational play — if you're ready, play. The guideline is 4 hours for 18 holes (about 13 minutes per hole). Let faster groups play through."),
            .init(title: "Be Quiet", body: "Stay still and quiet when another player is addressing the ball or swinging. Movement and noise are distracting."),
            .init(title: "Don't Stand in the Line", body: "Never stand directly behind a player's ball on their target line, or directly behind the hole. Stand to the side, outside the player's peripheral vision."),
            .init(title: "Repair Ball Marks", body: "Always fix your pitch mark on the green. A ball mark left unrepaired takes weeks to heal; one fixed immediately heals in 24 hours."),
            .init(title: "Rake Bunkers", body: "After playing from a bunker, rake the sand smooth for the next player. Leave the rake outside the bunker (check local rules — some require it inside)."),
            .init(title: "Replace Divots", body: "Replace your divots in the fairway, or fill them with sand/seed mix if provided. On the tee box, repair tee marks."),
            .init(title: "Keep Your Phone Quiet", body: "Put your phone on silent or vibrate. Take calls away from players who are playing their shots."),
            .init(badge: "TIP", badgeColor: .green, body: "The golden rule: leave the course in better condition than you found it."),
        ]
    )
}
