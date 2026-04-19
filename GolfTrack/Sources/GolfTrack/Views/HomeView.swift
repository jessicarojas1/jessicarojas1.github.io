import SwiftUI
import SwiftData

struct HomeView: View {
    @Environment(ActiveRoundManager.self) private var roundManager
    @Environment(\.modelContext) private var context
    @Query(sort: \Round.date, order: .reverse) private var rounds: [Round]
    @Binding var showActiveRound: Bool
    @State private var showCourseSheet  = false
    @State private var showNearbyCourses = false

    private var completedRounds: [Round] { rounds.filter { $0.isComplete } }

    private var handicapIndex: Double? {
        HandicapCalculator.handicapIndex(from: completedRounds)
    }

    private var averageScore: Double? {
        guard !completedRounds.isEmpty else { return nil }
        return Double(completedRounds.map { $0.totalStrokes }.reduce(0, +)) / Double(completedRounds.count)
    }

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 24) {

                // Handicap hero card
                HStack(spacing: 20) {
                    VStack(alignment: .leading, spacing: 4) {
                        Text("Handicap Index")
                            .font(.caption).foregroundStyle(.secondary)
                        if let hcp = handicapIndex {
                            Text(String(format: "%.1f", hcp))
                                .font(.system(size: 52, weight: .bold, design: .rounded))
                                .foregroundStyle(.green)
                        } else {
                            Text("—")
                                .font(.system(size: 52, weight: .bold, design: .rounded))
                                .foregroundStyle(.secondary)
                            Text("Play 3 rounds to establish")
                                .font(.caption).foregroundStyle(.secondary)
                        }
                    }
                    Spacer()
                    VStack(spacing: 12) {
                        StatPill(label: "Rounds",  value: "\(completedRounds.count)", icon: "flag.fill",       color: .blue)
                        StatPill(label: "Avg Score", value: averageScore.map { String(format: "%.1f", $0) } ?? "—",
                                 icon: "plusminus", color: .orange)
                    }
                }
                .padding()
                .background(.regularMaterial)
                .clipShape(RoundedRectangle(cornerRadius: 16))

                // Start round buttons
                if roundManager.isActive {
                    Button { showActiveRound = true } label: {
                        Label("Resume Round", systemImage: "arrow.right.circle.fill")
                            .font(.headline).frame(maxWidth: .infinity).padding()
                            .background(Color.green).foregroundStyle(.white)
                            .clipShape(RoundedRectangle(cornerRadius: 14))
                    }
                } else {
                    // Find nearby courses (GPS)
                    Button { showNearbyCourses = true } label: {
                        Label("Find Nearby Courses", systemImage: "location.fill")
                            .font(.headline).frame(maxWidth: .infinity).padding()
                            .background(Color.green).foregroundStyle(.white)
                            .clipShape(RoundedRectangle(cornerRadius: 14))
                    }
                    // Or pick from built-in list
                    Button { showCourseSheet = true } label: {
                        Label("Browse Course Library", systemImage: "map.fill")
                            .font(.callout).frame(maxWidth: .infinity).padding(12)
                            .background(Color.secondary.opacity(0.15))
                            .foregroundStyle(.primary)
                            .clipShape(RoundedRectangle(cornerRadius: 12))
                    }
                }

                // Recent rounds
                if !completedRounds.isEmpty {
                    Text("Recent Rounds").font(.title3.bold())
                    ForEach(completedRounds.prefix(5)) { round in
                        NavigationLink(destination: RoundDetailView(round: round)) {
                            RoundRowView(round: round)
                        }
                        .buttonStyle(.plain)
                    }
                } else {
                    ContentUnavailableView("No Rounds Yet",
                                          systemImage: "flag",
                                          description: Text("Start your first round to begin tracking."))
                }
            }
            .padding()
        }
        .navigationTitle("GolfTrack")
        .sheet(isPresented: $showCourseSheet) {
            CoursePickerSheet { course in
                roundManager.startRound(course: course, context: context)
                showCourseSheet = false
            }
        }
        .sheet(isPresented: $showNearbyCourses) {
            NearbyCoursesView { template in
                roundManager.startRound(template: template, context: context)
                showNearbyCourses = false
            }
        }
    }
}

// MARK: - Stat Pill

struct StatPill: View {
    let label: String; let value: String; let icon: String; let color: Color
    var body: some View {
        HStack(spacing: 8) {
            Image(systemName: icon).foregroundStyle(color).font(.callout)
            VStack(alignment: .leading, spacing: 1) {
                Text(value).font(.callout.bold())
                Text(label).font(.caption2).foregroundStyle(.secondary)
            }
        }
        .padding(.horizontal, 12).padding(.vertical, 8)
        .background(color.opacity(0.1))
        .clipShape(Capsule())
    }
}

// MARK: - Round Row

struct RoundRowView: View {
    let round: Round
    var body: some View {
        HStack {
            VStack(alignment: .leading, spacing: 4) {
                Text(round.courseName).font(.headline).lineLimit(1)
                Text(round.formattedDate).font(.caption).foregroundStyle(.secondary)
            }
            Spacer()
            VStack(alignment: .trailing, spacing: 4) {
                Text("\(round.totalStrokes)")
                    .font(.title3.bold())
                Text(round.scoreLabel)
                    .font(.caption.bold())
                    .foregroundStyle(round.scoreVsPar <= 0 ? .green : round.scoreVsPar <= 5 ? .orange : .red)
            }
        }
        .padding()
        .background(.regularMaterial)
        .clipShape(RoundedRectangle(cornerRadius: 12))
    }
}

// MARK: - Course Picker Sheet

struct CoursePickerSheet: View {
    let onSelect: (Course) -> Void
    @Environment(\.dismiss) private var dismiss
    @State private var searchText = ""

    private var courses: [Course] {
        let all = CourseLibrary.shared.featured
        guard !searchText.isEmpty else { return all }
        return all.filter { $0.name.localizedCaseInsensitiveContains(searchText) }
    }

    var body: some View {
        NavigationStack {
            List(courses) { course in
                Button {
                    onSelect(course)
                    dismiss()
                } label: {
                    VStack(alignment: .leading, spacing: 4) {
                        Text(course.name).font(.headline)
                        HStack {
                            Text(course.location)
                            Spacer()
                            Text("Par \(course.par)")
                            Text("Rating \(course.rating, specifier: "%.1f")")
                            Text("Slope \(course.slope)")
                        }
                        .font(.caption).foregroundStyle(.secondary)
                    }
                    .padding(.vertical, 4)
                }
                .buttonStyle(.plain)
            }
            .searchable(text: $searchText, prompt: "Search courses")
            .navigationTitle("Select Course")
#if os(iOS)
            .navigationBarTitleDisplayMode(.inline)
#endif
            .toolbar {
                ToolbarItem(placement: .cancellationAction) { Button("Cancel") { dismiss() } }
            }
        }
        .presentationDetents([.medium, .large])
    }
}
