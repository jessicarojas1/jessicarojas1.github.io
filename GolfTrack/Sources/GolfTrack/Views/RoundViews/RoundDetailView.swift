import SwiftUI

struct RoundDetailView: View {
    let round: Round
    @Environment(\.modelContext) private var context
    @Environment(\.dismiss) private var dismiss

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 20) {

                // Header stats
                HStack(spacing: 12) {
                    DetailCard(title: "Score",  value: "\(round.totalStrokes)",          color: .primary)
                    DetailCard(title: "vs Par", value: round.scoreLabel,
                               color: round.scoreVsPar < 0 ? .green : round.scoreVsPar == 0 ? .primary : .red)
                    DetailCard(title: "Putts",  value: "\(round.totalPutts)",            color: .blue)
                    DetailCard(title: "Diff",   value: String(format: "%.1f", round.scoreDifferential), color: .orange)
                }

                // Fairway / GIR stats
                HStack(spacing: 12) {
                    StatBar(title: "Fairways Hit", value: round.fairwayPct,
                            label: "\(round.fairwaysHit)/\(round.fairwayHoles)", color: .blue)
                    StatBar(title: "Greens in Reg", value: round.girPct,
                            label: "\(round.greensInReg)/\(round.holeScores.count)", color: .green)
                }

                // Hole-by-hole scorecard
                Text("Scorecard").font(.headline)
                ScorecardGrid(scores: round.sortedScores, par: round.coursePar)

                // Score distribution
                Text("Score Distribution").font(.headline)
                ScoreDistributionView(scores: round.sortedScores)
            }
            .padding()
        }
        .navigationTitle(round.courseName)
#if os(iOS)
        .navigationBarTitleDisplayMode(.large)
#endif
        .toolbar {
            ToolbarItem(placement: .destructiveAction) {
                Button(role: .destructive) {
                    context.delete(round); dismiss()
                } label: {
                    Label("Delete", systemImage: "trash")
                }
            }
        }
    }
}

// MARK: - Sub-views

struct DetailCard: View {
    let title: String; let value: String; let color: Color
    var body: some View {
        VStack(spacing: 4) {
            Text(value).font(.title2.bold()).foregroundStyle(color)
            Text(title).font(.caption).foregroundStyle(.secondary)
        }
        .frame(maxWidth: .infinity).padding()
        .background(.regularMaterial)
        .clipShape(RoundedRectangle(cornerRadius: 10))
    }
}

struct StatBar: View {
    let title: String; let value: Double; let label: String; let color: Color
    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack {
                Text(title).font(.caption).foregroundStyle(.secondary)
                Spacer()
                Text(label).font(.caption.bold())
            }
            ProgressView(value: value, total: 100)
                .tint(color)
            Text(String(format: "%.0f%%", value)).font(.caption2).foregroundStyle(.secondary)
        }
        .padding()
        .background(.regularMaterial)
        .clipShape(RoundedRectangle(cornerRadius: 10))
    }
}

struct ScorecardGrid: View {
    let scores: [HoleScore]; let par: Int
    private let cols = 9

    var body: some View {
        VStack(spacing: 0) {
            // Header
            ScorecardRow(cells: ["Hole"] + (1...9).map { "\($0)" } + ["Out"],
                         bold: true, background: .secondary.opacity(0.15))
            ScorecardRow(cells: ["Par"] + scores.prefix(9).map { "\($0.par)" }
                         + ["\(scores.prefix(9).reduce(0) { $0 + $1.par })"],
                         bold: false, background: .clear)
            ScorecardRow(cells: ["Score"] + scores.prefix(9).map { $0.strokes > 0 ? "\($0.strokes)" : "-" }
                         + ["\(scores.prefix(9).reduce(0) { $0 + $1.strokes })"],
                         bold: false, background: .blue.opacity(0.05), scores: Array(scores.prefix(9)))

            if scores.count > 9 {
                ScorecardRow(cells: ["Hole"] + (10...18).map { "\($0)" } + ["In"],
                             bold: true, background: .secondary.opacity(0.15))
                ScorecardRow(cells: ["Par"] + scores.dropFirst(9).map { "\($0.par)" }
                             + ["\(scores.dropFirst(9).reduce(0) { $0 + $1.par })"],
                             bold: false, background: .clear)
                ScorecardRow(cells: ["Score"] + scores.dropFirst(9).map { $0.strokes > 0 ? "\($0.strokes)" : "-" }
                             + ["\(scores.dropFirst(9).reduce(0) { $0 + $1.strokes })"],
                             bold: false, background: .blue.opacity(0.05), scores: Array(scores.dropFirst(9)))
            }

            // Total
            ScorecardRow(cells: ["Total", "", "", "", "", "", "", "", "", "", "\(scores.reduce(0){$0+$1.strokes})"],
                         bold: true, background: .green.opacity(0.1))
        }
        .clipShape(RoundedRectangle(cornerRadius: 10))
        .overlay(RoundedRectangle(cornerRadius: 10).stroke(Color.secondary.opacity(0.2)))
    }
}

struct ScorecardRow: View {
    let cells: [String]; let bold: Bool; let background: Color
    var scores: [HoleScore]? = nil
    var body: some View {
        HStack(spacing: 0) {
            ForEach(Array(cells.enumerated()), id: \.0) { i, cell in
                Text(cell)
                    .font(bold ? .caption.bold() : .caption)
                    .foregroundStyle(scoreColor(i))
                    .frame(maxWidth: .infinity).padding(6)
                    .background(background)
                if i < cells.count - 1 { Divider() }
            }
        }
        Divider()
    }
    private func scoreColor(_ i: Int) -> Color {
        guard let scores, i > 0, i <= scores.count else { return .primary }
        let s = scores[i - 1]
        if s.strokes == 0 { return .secondary }
        switch s.scoreVsPar {
        case ..<0:  return .yellow
        case 0:     return .green
        case 1:     return .blue
        default:    return .red
        }
    }
}

struct ScoreDistributionView: View {
    let scores: [HoleScore]
    private var dist: [(name: String, count: Int, color: Color)] {
        let all = scores.filter { $0.strokes > 0 }
        return [
            ("Eagle+",  all.filter { $0.scoreVsPar <= -2 }.count, .yellow),
            ("Birdie",  all.filter { $0.scoreVsPar == -1 }.count, .green),
            ("Par",     all.filter { $0.scoreVsPar == 0  }.count, .blue),
            ("Bogey",   all.filter { $0.scoreVsPar == 1  }.count, .orange),
            ("Double+", all.filter { $0.scoreVsPar >= 2  }.count, .red),
        ]
    }
    var body: some View {
        HStack(spacing: 8) {
            ForEach(dist, id: \.name) { item in
                VStack(spacing: 4) {
                    Text("\(item.count)").font(.title3.bold()).foregroundStyle(item.color)
                    Text(item.name).font(.caption2).foregroundStyle(.secondary)
                        .multilineTextAlignment(.center)
                }
                .frame(maxWidth: .infinity)
                .padding(.vertical, 10)
                .background(item.color.opacity(0.1))
                .clipShape(RoundedRectangle(cornerRadius: 10))
            }
        }
    }
}
