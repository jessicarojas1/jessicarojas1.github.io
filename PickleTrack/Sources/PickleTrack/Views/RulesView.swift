import SwiftUI

struct RulesView: View {
    @State private var expandedSection: String? = nil

    var body: some View {
        List {
            ForEach(RulesContent.sections) { section in
                Section {
                    DisclosureGroup(
                        isExpanded: Binding(
                            get: { expandedSection == section.id },
                            set: { expandedSection = $0 ? section.id : nil }
                        )
                    ) {
                        VStack(alignment: .leading, spacing: 14) {
                            ForEach(section.items) { item in
                                RuleRow(item: item)
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
                    Text("These rules follow USA Pickleball's official rulebook. For tournament play, always defer to the official rules at usapickleball.org.")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
                .padding(.vertical, 4)
            }
        }
        .navigationTitle("Rules & Scoring")
#if os(iOS)
        .navigationBarTitleDisplayMode(.large)
#endif
    }
}

// MARK: - Row

private struct RuleRow: View {
    let item: RuleItem

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

// MARK: - Content model

struct RulesSection: Identifiable {
    let id:    String
    let title: String
    let icon:  String
    let items: [RuleItem]
}

struct RuleItem: Identifiable {
    let id     = UUID()
    var title:      String?   = nil
    var badge:      String?   = nil
    var badgeColor: Color     = .green
    var body:       String
}

// MARK: - All content

enum RulesContent {
    static let sections: [RulesSection] = [
        overview, scoring, serving, kitchen, faults, winning
    ]

    static let overview = RulesSection(
        id: "overview", title: "The Game", icon: "sportscourt.fill",
        items: [
            .init(body: "Pickleball is played on a 44×20 ft court (same size as a doubles badminton court) with a net set to 34 inches at the center."),
            .init(body: "It can be played as singles (1v1) or doubles (2v2). Doubles is the most common format."),
            .init(body: "Players use solid paddles to hit a perforated plastic ball (similar to a wiffle ball) over the net."),
            .init(body: "Each rally starts with an underhand serve and ends when a fault is committed."),
            .init(title: "Court Zones", body: "The court is divided into two service boxes on each side and a 7-foot non-volley zone (the Kitchen) on each side of the net."),
        ]
    )

    static let scoring = RulesSection(
        id: "scoring", title: "Scoring", icon: "number.circle.fill",
        items: [
            .init(title: "Side-Out Scoring", body: "Only the serving team can score points. If the serving team wins the rally, they score a point. If the receiving team wins the rally, they earn the serve (a side-out) but no point."),
            .init(title: "Score Call", body: "Before every serve, the server must announce the score aloud in this order:\n1. Serving team's score\n2. Receiving team's score\n3. Server number (doubles only — 1 or 2)\n\nExample: \"4–2–1\" means serving team has 4, receiving team has 2, and this is server 1."),
            .init(badge: "Doubles", badgeColor: .blue, body: "Each team has two servers per side-out. Server 1 serves until their team loses a rally, then Server 2 serves. When Server 2's team loses a rally, it's a side-out and the other team serves."),
            .init(badge: "Singles", badgeColor: .purple, body: "There is only one server per side. If you win the rally while serving you score a point. If you lose you give up the serve — no point for the other player."),
            .init(title: "First-Serve Rule", body: "At the start of each game, the first serving team begins with only one server (called 'server 2'). This prevents the first-serving team from having a full two-server advantage right away. Every subsequent side-out gives both servers their turn."),
            .init(title: "Win by 2", body: "A team must win by at least 2 points. If the score reaches 10–10 (in an to-11 game), play continues until one team leads by 2. Same rule applies at 14–14 or 20–20."),
        ]
    )

    static let serving = RulesSection(
        id: "serving", title: "Serving", icon: "arrow.up.right.circle.fill",
        items: [
            .init(title: "Underhand Only", body: "The serve must be hit with an underhand motion. The paddle must contact the ball below the server's waist (naval level), and the paddle head must be below the wrist at contact."),
            .init(title: "Diagonal Service", body: "The serve must land in the diagonally opposite service box — crosscourt. It must clear the non-volley zone (kitchen) and land within the service box. Hitting the kitchen line on a serve is a fault."),
            .init(title: "Behind the Baseline", body: "The server must keep both feet behind the baseline when serving. At least one foot must remain behind the baseline at the time of contact."),
            .init(title: "One Attempt", body: "Each server gets only one serve attempt. There are no let serves — if the ball clips the top of the net and lands in the correct service box, play continues."),
            .init(badge: "Doubles", badgeColor: .blue, body: "In doubles, the correct server must serve from the correct side. When your team's score is even (0, 2, 4…) Server 1 serves from the right side. When odd (1, 3, 5…) Server 1 serves from the left side."),
            .init(badge: "Doubles", badgeColor: .blue, body: "Partners switch sides only when their team scores a point. They do not switch on a side-out."),
        ]
    )

    static let kitchen = RulesSection(
        id: "kitchen", title: "The Kitchen (NVZ)", icon: "flame.fill",
        items: [
            .init(title: "What is it?", body: "The Non-Volley Zone (NVZ), nicknamed 'the Kitchen,' is the 7-foot area on each side of the net. Its boundary lines are considered part of the NVZ."),
            .init(title: "No Volleying", body: "You cannot volley (hit the ball out of the air) while standing in the Kitchen or touching the Kitchen line. This includes stepping into the Kitchen as a result of the momentum from a volley."),
            .init(title: "Bounced balls are fine", body: "You can enter the Kitchen anytime to hit a ball that has bounced. You just can't volley from in there."),
            .init(title: "The Two-Bounce Rule", body: "After the serve, each team must let the ball bounce once before volleying. The serve must bounce, then the return must bounce. After those two bounces, either team may volley or play off the bounce freely."),
            .init(badge: "TIP", badgeColor: .green, body: "Mastering the kitchen line — dinking softly and waiting for a ball you can attack — is the hallmark of advanced pickleball play."),
        ]
    )

    static let faults = RulesSection(
        id: "faults", title: "Faults", icon: "xmark.circle.fill",
        items: [
            .init(body: "A fault ends the rally. If the serving team faults, it's a side-out (or loss of server in doubles). If the receiving team faults, the serving team scores a point."),
            .init(badge: "FAULT", badgeColor: .red, body: "Ball lands out of bounds (outside the court lines)."),
            .init(badge: "FAULT", badgeColor: .red, body: "Ball hits the net and does not cross to the other side."),
            .init(badge: "FAULT", badgeColor: .red, body: "Server's ball lands in the Kitchen or on the Kitchen line."),
            .init(badge: "FAULT", badgeColor: .red, body: "Player volleys from inside the Kitchen or steps into the Kitchen after volleying."),
            .init(badge: "FAULT", badgeColor: .red, body: "Ball is volleyed before the two-bounce rule is satisfied."),
            .init(badge: "FAULT", badgeColor: .red, body: "Ball bounces twice before being struck."),
            .init(badge: "FAULT", badgeColor: .red, body: "Player touches the net, net post, or the opponent's court during play."),
            .init(badge: "FAULT", badgeColor: .red, body: "Ball strikes a player or anything they are wearing/carrying (except the paddle hand below the wrist)."),
        ]
    )

    static let winning = RulesSection(
        id: "winning", title: "Winning a Match", icon: "trophy.fill",
        items: [
            .init(title: "Standard Game", body: "Most recreational and tournament games are played to 11 points, win by 2. Some formats use 15 or 21."),
            .init(title: "Best of 3 or 5", body: "Tournament matches are typically best of 3 games. Major championships may use best of 5. The first team to win the required number of games wins the match."),
            .init(title: "Third Game", body: "In a best-of-3 match that reaches a third game, some formats play that game to 15 instead of 11 (check the specific tournament rules)."),
            .init(title: "Switching Sides", body: "In the third game of a match, players switch sides when the first team reaches 6 points (in an 11-point game) or 8 points (in a 15-point game). This evens out any court or lighting advantage."),
            .init(badge: "TIP", badgeColor: .green, body: "Keep the score call honest. Disputes almost always arise because the score wasn't announced before the serve. Get in the habit of calling it every time."),
        ]
    )
}
