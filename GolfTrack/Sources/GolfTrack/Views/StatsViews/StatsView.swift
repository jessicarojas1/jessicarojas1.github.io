import SwiftUI
import SwiftData

struct StatsView: View {
    @Query(sort: \Round.date, order: .reverse) private var allRounds: [Round]
    @State private var range = 20

    private var rounds: [Round] { Array(allRounds.filter { $0.isComplete }.prefix(range)) }

    private var avgScore: Double? {
        guard !rounds.isEmpty else { return nil }
        return Double(rounds.map { $0.totalStrokes }.reduce(0, +)) / Double(rounds.count)
    }
    private var bestScore: Int? { rounds.map { $0.totalStrokes }.min() }
    private var avgPutts: Double? {
        guard !rounds.isEmpty else { return nil }
        return Double(rounds.map { $0.totalPutts }.reduce(0, +)) / Double(rounds.count)
    }
    private var avgFairways: Double? {
        let r = rounds.filter { $0.fairwayHoles > 0 }
        guard !r.isEmpty else { return nil }
        return r.map { $0.fairwayPct }.reduce(0, +) / Double(r.count)
    }
    private var avgGIR: Double? {
        guard !rounds.isEmpty else { return nil }
        return rounds.map { $0.girPct }.reduce(0, +) / Double(rounds.count)
    }
    private var handicap: Double? { HandicapCalculator.handicapIndex(from: rounds) }

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 24) {

                // Range picker
                Picker("Last N rounds", selection: $range) {
                    Text("Last 5").tag(5)
                    Text("Last 10").tag(10)
                    Text("Last 20").tag(20)
                    Text("All").tag(999)
                }
                .pickerStyle(.segmented)

                if rounds.isEmpty {
                    ContentUnavailableView("Not Enough Data",
                                          systemImage: "chart.bar",
                                          description: Text("Play some rounds to see your stats."))
                } else {
                    // Key metrics
                    LazyVGrid(columns: [GridItem(.flexible()), GridItem(.flexible())], spacing: 12) {
                        MetricCard(title: "Handicap Index",
                                   value: handicap.map { String(format: "%.1f", $0) } ?? "N/A",
                                   subtitle: "WHS",                              icon: "person.fill", color: .green)
                        MetricCard(title: "Avg Score",
                                   value: avgScore.map { String(format: "%.1f", $0) } ?? "—",
                                   subtitle: "per round",                        icon: "flag.fill",  color: .blue)
                        MetricCard(title: "Best Score",
                                   value: bestScore.map { "\($0)" } ?? "—",
                                   subtitle: "lowest round",                     icon: "star.fill",  color: .yellow)
                        MetricCard(title: "Avg Putts",
                                   value: avgPutts.map { String(format: "%.1f", $0) } ?? "—",
                                   subtitle: "per round",                        icon: "circle",     color: .purple)
                        MetricCard(title: "Fairways Hit",
                                   value: avgFairways.map { String(format: "%.0f%%", $0) } ?? "—",
                                   subtitle: "avg",                              icon: "arrow.up",   color: .orange)
                        MetricCard(title: "Greens in Reg",
                                   value: avgGIR.map { String(format: "%.0f%%", $0) } ?? "—",
                                   subtitle: "avg",                              icon: "checkmark",  color: .teal)
                    }

                    // Score trend
                    if rounds.count >= 3 {
                        Text("Score Trend").font(.headline)
                        ScoreTrendChart(rounds: rounds.reversed())
                    }

                    // Score distribution across all rounds
                    Text("Overall Score Distribution").font(.headline)
                    let allScores = rounds.flatMap { $0.holeScores }.filter { $0.strokes > 0 }
                    ScoreDistributionView(scores: allScores)
                }
            }
            .padding()
        }
        .navigationTitle("Stats")
    }
}

// MARK: - Metric Card

struct MetricCard: View {
    let title: String; let value: String; let subtitle: String; let icon: String; let color: Color
    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack {
                Image(systemName: icon).foregroundStyle(color)
                Text(title).font(.caption).foregroundStyle(.secondary)
            }
            Text(value).font(.title2.bold()).foregroundStyle(color)
            Text(subtitle).font(.caption2).foregroundStyle(.secondary)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding()
        .background(.regularMaterial)
        .clipShape(RoundedRectangle(cornerRadius: 12))
    }
}

// MARK: - Simple score trend chart (no Charts framework dependency)

struct ScoreTrendChart: View {
    let rounds: [Round]
    private var scores: [Int] { rounds.map { $0.totalStrokes } }
    private var minScore: Int { (scores.min() ?? 70) - 2 }
    private var maxScore: Int { (scores.max() ?? 100) + 2 }
    private var range: Int { max(1, maxScore - minScore) }

    var body: some View {
        GeometryReader { geo in
            let w = geo.size.width
            let h = geo.size.height
            let step = w / CGFloat(max(1, scores.count - 1))

            ZStack {
                // Grid lines
                ForEach([0, 25, 50, 75, 100], id: \.self) { pct in
                    let y = h * CGFloat(100 - pct) / 100
                    Path { p in p.move(to: CGPoint(x: 0, y: y)); p.addLine(to: CGPoint(x: w, y: y)) }
                        .stroke(Color.secondary.opacity(0.15), lineWidth: 1)
                }

                // Line
                Path { p in
                    for (i, score) in scores.enumerated() {
                        let x = CGFloat(i) * step
                        let y = h * CGFloat(maxScore - score) / CGFloat(range)
                        if i == 0 { p.move(to: CGPoint(x: x, y: y)) }
                        else       { p.addLine(to: CGPoint(x: x, y: y)) }
                    }
                }
                .stroke(Color.green, lineWidth: 2)

                // Dots
                ForEach(Array(scores.enumerated()), id: \.0) { i, score in
                    let x = CGFloat(i) * step
                    let y = h * CGFloat(maxScore - score) / CGFloat(range)
                    Circle().fill(Color.green).frame(width: 8, height: 8)
                        .position(x: x, y: y)
                    Text("\(score)").font(.system(size: 9)).foregroundStyle(.secondary)
                        .position(x: x, y: y - 12)
                }
            }
        }
        .frame(height: 140)
        .padding()
        .background(.regularMaterial)
        .clipShape(RoundedRectangle(cornerRadius: 12))
    }
}
