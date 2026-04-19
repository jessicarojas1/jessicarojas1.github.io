import SwiftUI
import SwiftData

struct StatsView: View {
    @Query(filter: #Predicate<PBMatch> { $0.isComplete },
           sort: \PBMatch.date, order: .reverse)
    private var matches: [PBMatch]

    var body: some View {
        Group {
            if matches.isEmpty {
                ContentUnavailableView(
                    "No Stats Yet",
                    systemImage: "chart.bar",
                    description: Text("Play some matches to see your stats.")
                )
            } else {
                ScrollView {
                    VStack(spacing: 20) {
                        StatGridView(matches: matches)
                            .padding(.horizontal)
                        WinLossChart(matches: matches)
                            .padding(.horizontal)
                        RecentResultsList(matches: Array(matches.prefix(10)))
                            .padding(.horizontal)
                    }
                    .padding(.vertical)
                }
            }
        }
        .navigationTitle("Stats")
    }
}

// MARK: - Stat Grid

private struct StatGridView: View {
    let matches: [PBMatch]

    private var wins:   Int { matches.filter { $0.winner == 1 }.count }
    private var losses: Int { matches.filter { $0.winner == 2 }.count }
    private var winPct: Double { matches.isEmpty ? 0 : Double(wins) / Double(matches.count) * 100 }

    private var avgPointsFor: Double {
        guard !matches.isEmpty else { return 0 }
        let total = matches.flatMap { $0.games }.reduce(0) { $0 + $1.team1Score }
        let games = matches.flatMap { $0.games }.count
        return games > 0 ? Double(total) / Double(games) : 0
    }

    private var avgPointsAgainst: Double {
        guard !matches.isEmpty else { return 0 }
        let total = matches.flatMap { $0.games }.reduce(0) { $0 + $1.team2Score }
        let games = matches.flatMap { $0.games }.count
        return games > 0 ? Double(total) / Double(games) : 0
    }

    private var streak: Int {
        var count = 0
        for m in matches {
            if m.winner == 1 { count += 1 } else { break }
        }
        return count
    }

    var body: some View {
        LazyVGrid(columns: [GridItem(.flexible()), GridItem(.flexible())], spacing: 12) {
            StatCard(title: "Win Rate",  value: String(format: "%.0f%%", winPct), color: .green)
            StatCard(title: "Matches",   value: "\(matches.count)",               color: .blue)
            StatCard(title: "Wins",      value: "\(wins)",                         color: .green)
            StatCard(title: "Losses",    value: "\(losses)",                       color: .red)
            StatCard(title: "Avg Pts For",     value: String(format: "%.1f", avgPointsFor),     color: .blue)
            StatCard(title: "Avg Pts Against", value: String(format: "%.1f", avgPointsAgainst), color: .orange)
            StatCard(title: "Win Streak", value: "\(streak)", color: .yellow)
            StatCard(title: "Games Played",
                     value: "\(matches.flatMap { $0.games }.count)", color: .purple)
        }
    }
}

private struct StatCard: View {
    let title: String; let value: String; let color: Color
    var body: some View {
        VStack(spacing: 4) {
            Text(value).font(.title2.bold()).foregroundStyle(color)
            Text(title).font(.caption2).foregroundStyle(.secondary).multilineTextAlignment(.center)
        }
        .frame(maxWidth: .infinity)
        .padding()
        .background(.regularMaterial)
        .clipShape(RoundedRectangle(cornerRadius: 12))
    }
}

// MARK: - Win/Loss chart (simple bar)

private struct WinLossChart: View {
    let matches: [PBMatch]

    private var recent: [PBMatch] { Array(matches.suffix(10).reversed()) }

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            Text("Last 10 Results")
                .font(.caption.weight(.semibold))
                .foregroundStyle(.secondary)

            HStack(spacing: 6) {
                ForEach(recent) { m in
                    RoundedRectangle(cornerRadius: 4)
                        .fill(m.winner == 1 ? Color.green : Color.red)
                        .frame(maxWidth: .infinity)
                        .frame(height: 36)
                        .overlay(
                            Text(m.winner == 1 ? "W" : "L")
                                .font(.caption2.bold())
                                .foregroundStyle(.white)
                        )
                }
                // Placeholder bars if fewer than 10
                ForEach(0..<max(0, 10 - recent.count), id: \.self) { _ in
                    RoundedRectangle(cornerRadius: 4)
                        .fill(Color.secondary.opacity(0.15))
                        .frame(maxWidth: .infinity)
                        .frame(height: 36)
                }
            }
        }
        .padding()
        .background(.regularMaterial)
        .clipShape(RoundedRectangle(cornerRadius: 12))
    }
}

// MARK: - Recent results list

private struct RecentResultsList: View {
    let matches: [PBMatch]
    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            Text("Recent Matches")
                .font(.caption.weight(.semibold))
                .foregroundStyle(.secondary)
            ForEach(matches) { m in
                HStack {
                    Image(systemName: m.winner == 1 ? "checkmark.circle.fill" : "xmark.circle.fill")
                        .foregroundStyle(m.winner == 1 ? .green : .red)
                    Text(m.displayName).font(.callout).lineLimit(1)
                    Spacer()
                    Text(m.scoreString).font(.caption.monospacedDigit()).foregroundStyle(.secondary)
                }
                .padding(.vertical, 2)
                if m.id != matches.last?.id { Divider() }
            }
        }
        .padding()
        .background(.regularMaterial)
        .clipShape(RoundedRectangle(cornerRadius: 12))
    }
}
