import SwiftUI

struct ClubRecommendationCard: View {
    let yardage: Int
    @Environment(ClubProfile.self) private var profile

    private var recs: [ClubRecommendation] { profile.recommendations(for: yardage) }

    var body: some View {
        if yardage > 0, !recs.isEmpty {
            VStack(alignment: .leading, spacing: 8) {
                HStack {
                    Image(systemName: "figure.golf.circle.fill")
                        .foregroundStyle(.green)
                        .font(.callout)
                    Text("Club Suggestion")
                        .font(.caption.weight(.semibold))
                        .foregroundStyle(.secondary)
                }

                HStack(spacing: 12) {
                    ForEach(recs) { rec in
                        ClubBadge(rec: rec)
                    }
                    Spacer()
                }
            }
            .padding(.horizontal, 14)
            .padding(.vertical, 10)
            .background(.regularMaterial)
            .clipShape(RoundedRectangle(cornerRadius: 12))
        }
    }
}

private struct ClubBadge: View {
    let rec: ClubRecommendation

    private var borderColor: Color {
        rec.role == .primary ? .green : .secondary.opacity(0.5)
    }

    private var deltaLabel: String {
        if rec.delta == 0 { return "exact" }
        return rec.delta > 0 ? "+\(rec.delta) yds" : "\(rec.delta) yds"
    }

    private var deltaColor: Color {
        rec.delta > 0 ? .secondary : .orange
    }

    var body: some View {
        VStack(spacing: 3) {
            Text(rec.club.emoji)
                .font(.title3)
            Text(rec.club.rawValue)
                .font(.caption.bold())
            Text("\(rec.distance) yds")
                .font(.caption2)
                .foregroundStyle(.secondary)
            Text(deltaLabel)
                .font(.system(size: 9))
                .foregroundStyle(deltaColor)
        }
        .frame(minWidth: 72)
        .padding(.vertical, 8)
        .padding(.horizontal, 6)
        .background(rec.role == .primary ? Color.green.opacity(0.1) : Color.secondary.opacity(0.08))
        .clipShape(RoundedRectangle(cornerRadius: 10))
        .overlay(
            RoundedRectangle(cornerRadius: 10)
                .stroke(borderColor, lineWidth: rec.role == .primary ? 1.5 : 0.5)
        )
    }
}
