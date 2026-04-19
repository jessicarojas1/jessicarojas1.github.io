import SwiftUI
import SwiftData

struct CourseLibraryView: View {
    @Query private var customCourses: [CustomCourse]
    @Environment(\.modelContext) private var context
    @State private var showAddCourse = false
    @State private var editCourse: CustomCourse?

    var body: some View {
        List {
            Section("Featured Courses") {
                ForEach(CourseLibrary.shared.featured) { course in
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
            }

            Section("My Courses") {
                if customCourses.isEmpty {
                    Text("No custom courses yet.")
                        .foregroundStyle(.secondary).font(.callout)
                } else {
                    ForEach(customCourses) { course in
                        Button { editCourse = course } label: {
                            VStack(alignment: .leading, spacing: 4) {
                                Text(course.name).font(.headline).foregroundStyle(.primary)
                                HStack {
                                    Text(course.location.isEmpty ? "Custom" : course.location)
                                    Spacer()
                                    Text("Par \(course.totalPar)")
                                    Text("Rating \(course.rating, specifier: "%.1f")")
                                    Text("Slope \(course.slope)")
                                }
                                .font(.caption).foregroundStyle(.secondary)
                            }
                        }
                        .buttonStyle(.plain)
                        .padding(.vertical, 4)
                    }
                    .onDelete { idx in idx.forEach { context.delete(customCourses[$0]) } }
                }

                Button { showAddCourse = true } label: {
                    Label("Add Course", systemImage: "plus.circle.fill")
                        .foregroundStyle(.accentColor)
                }
            }
        }
        .navigationTitle("Courses")
        .sheet(isPresented: $showAddCourse) {
            CourseEditorView(existingCourse: nil)
        }
        .sheet(item: $editCourse) { course in
            CourseEditorView(existingCourse: course)
        }
    }
}

// MARK: - Course Editor

struct CourseEditorView: View {
    var existingCourse: CustomCourse?
    @Environment(\.modelContext) private var context
    @Environment(\.dismiss) private var dismiss

    @State private var name = ""
    @State private var location = ""
    @State private var rating = "72.0"
    @State private var slope = "113"
    @State private var holes: [HoleInfo] = []

    var body: some View {
        NavigationStack {
            Form {
                Section("Course Info") {
                    TextField("Course Name", text: $name)
                    TextField("Location (optional)", text: $location)
                    HStack {
                        Text("Course Rating")
                        Spacer()
                        TextField("72.0", text: $rating)
                            .multilineTextAlignment(.trailing).frame(width: 70)
#if os(iOS)
                            .keyboardType(.decimalPad)
#endif
                    }
                    HStack {
                        Text("Slope Rating")
                        Spacer()
                        TextField("113", text: $slope)
                            .multilineTextAlignment(.trailing).frame(width: 70)
#if os(iOS)
                            .keyboardType(.numberPad)
#endif
                    }
                }

                Section("Holes") {
                    ForEach($holes) { $hole in
                        HStack {
                            Text("Hole \(hole.number)").frame(width: 60, alignment: .leading)
                            Text("Par")
                            Picker("", selection: Binding(
                                get: { hole.par },
                                set: { hole = HoleInfo(number: hole.number, par: $0,
                                                       yardage: hole.yardage, handicapIndex: hole.handicapIndex) }
                            )) {
                                Text("3").tag(3); Text("4").tag(4); Text("5").tag(5)
                            }
                            .pickerStyle(.segmented).frame(width: 120)
                        }
                    }
                }
            }
            .navigationTitle(existingCourse == nil ? "Add Course" : "Edit Course")
#if os(iOS)
            .navigationBarTitleDisplayMode(.inline)
#endif
            .toolbar {
                ToolbarItem(placement: .cancellationAction) { Button("Cancel") { dismiss() } }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Save") { save() }
                        .disabled(name.isEmpty)
                        .fontWeight(.bold)
                }
            }
            .onAppear { load() }
        }
    }

    private func load() {
        if let c = existingCourse {
            name = c.name; location = c.location
            rating = String(format: "%.1f", c.rating); slope = "\(c.slope)"
            holes = c.holes
        } else {
            holes = (1...18).map { n in
                let defaultPars = [4,4,3,5,4,3,4,5,4,4,3,4,5,4,3,4,4,5]
                return HoleInfo(number: n, par: defaultPars[n-1], yardage: 400, handicapIndex: n)
            }
        }
    }

    private func save() {
        let r = Double(rating) ?? 72.0
        let s = Int(slope) ?? 113
        if let c = existingCourse {
            c.name = name; c.location = location; c.rating = r; c.slope = s; c.holes = holes
        } else {
            let c = CustomCourse(name: name, location: location, rating: r, slope: s)
            c.holes = holes
            context.insert(c)
        }
        dismiss()
    }
}
